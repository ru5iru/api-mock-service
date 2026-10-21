<?php

namespace Tests\Feature;

use App\Models\MockEndpoint;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real simultaneous SDK clients against eight HTTP workers. */
final class ConcurrentMcpMutationTest extends TestCase
{
    public function test_concurrent_creates_replay_once_and_updates_require_one_winning_version(): void
    {
        if (! function_exists('posix_kill')) {
            if (getenv('MOCKDECK_MCP_CONCURRENCY_POSTGRES') === 'true') {
                self::fail('PostgreSQL CI requires POSIX process cleanup.');
            }
            $this->markTestSkipped('POSIX worker cleanup required; CI uses Linux.');
        }
        $postgres = getenv('MOCKDECK_MCP_CONCURRENCY_POSTGRES') === 'true';
        $original = config('database.connections.sqlite');
        $default = config('database.default');
        $database = tempnam(sys_get_temp_dir(), 'mockdeck-mcp-race-');
        $server = null;
        $pid = null;
        $clients = [];
        $files = [];
        try {
            if ($postgres) {
                self::assertSame('pgsql', $default);
                self::assertSame('mockdeck_run_isolation_test', config('database.connections.pgsql.database'), 'Only the disposable CI database can be migrated.');
                $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            } else {
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.busy_timeout' => 30000]);
                DB::purge('sqlite');
                $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            }
            $issued = app(McpCredentialService::class)->generate('race', ['read', 'write', 'cleanup']);
            $token = $issued->reveal();
            $call = fn ($name, $args) => app(McpDispatcher::class)->call($name, $args, $issued->credential);
            $run = ['run_id' => $call('start_test_run', ['label' => 'parallel MCP'])['data']['run_id']];
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'PHP_CLI_SERVER_WORKERS' => '8', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MOCK_DASHBOARD_AUTH_ENABLED' => 'false', 'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false'];
            if (! $postgres) {
                $env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
            }
            $server = new Process(['setsid', PHP_BINARY, '-S', $address, '-t', public_path(), public_path('index.php')], base_path(), $env);
            $server->start();
            $pid = $server->getPid();
            foreach (range(1, 80) as $_) {
                if (@file_get_contents('http://'.$address.'/up') !== false) {
                    break;
                } usleep(50000);
            }
            $payload = [...$run, 'idempotency_key' => 'raced-create', 'configuration' => ['curl' => 'curl http://localhost/race', 'responses' => [['body' => 'original']]]];
            foreach (['create_mock' => [$payload, $payload, $payload, $payload], 'update_mock' => null] as $tool => $inputs) {
                if ($tool === 'update_mock') {
                    $endpoint = MockEndpoint::withoutGlobalScope('ordinary')->sole();
                    $inputs = array_map(fn ($name) => [...$run, 'mock_id' => $endpoint->uuid, 'expected_version' => 1, 'changes' => ['endpoint' => ['name' => $name]]], ['first edit', 'second edit']);
                }
                $barrier = $database.'.'.$tool.'.go';
                $files[] = $barrier;
                $clients = [];
                foreach ($inputs as $index => $input) {
                    $file = $database.'.'.$tool.'.'.$index.'.json';
                    $files[] = $file;
                    file_put_contents($file, json_encode($input));
                    $client = new Process([PHP_BINARY, base_path('tests/Support/mcp-concurrent-client.php'), $barrier, 'http://'.$address.'/mcp', $tool, $file], base_path(), ['MCP_TEST_TOKEN' => $token]);
                    $client->setTimeout(60);
                    $client->start();
                    $clients[] = $client;
                }
                touch($barrier);
                $results = [];
                foreach ($clients as $client) {
                    $client->wait();
                    self::assertTrue($client->isSuccessful(), $client->getErrorOutput());
                    $results[] = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }
                if ($tool === 'create_mock') {
                    foreach ($results as $result) {
                        self::assertTrue($result['ok'], json_encode($result));
                    }
                    self::assertCount(1, array_unique(array_column(array_column($results, 'data'), 'mock_id')));
                    self::assertSame(1, MockEndpoint::withoutGlobalScope('ordinary')->count());
                } else {
                    self::assertCount(1, array_filter($results, fn ($row) => $row['ok']));
                    self::assertSame(['CONFLICT'], array_values(array_column(array_column(array_filter($results, fn ($row) => ! $row['ok']), 'error'), 'code')));
                }
            }
        } finally {
            foreach ($clients as $client) {
                if ($client->isRunning()) {
                    $client->stop();
                }
            }
            if ($pid !== null) {
                @posix_kill(-$pid, SIGTERM);
            } $server?->stop();
            DB::purge('sqlite');
            config(['database.default' => $default, 'database.connections.sqlite' => $original]);
            foreach ([$database, ...$files] as $file) {
                @unlink($file);
            }
        }
    }
}
