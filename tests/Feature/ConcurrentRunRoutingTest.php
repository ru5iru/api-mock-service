<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Mcp\McpCredentialService;
use App\Services\TestRuns\TestRunService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real TCP HTTP against eight PHP workers, not sequential in-process dispatch. */
final class ConcurrentRunRoutingTest extends TestCase
{
    public function test_simultaneous_identical_requests_in_two_runs_and_ordinary_scope_never_leak(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
            if (getenv('MOCKDECK_RUN_CONCURRENCY_POSTGRES') === 'true') {
                self::fail('The PostgreSQL CI run requires POSIX process cleanup and PHP server workers.');
            }
            $this->markTestSkipped('Requires Unix PHP server workers and POSIX process cleanup (CI uses Linux).');
        }
        $original = config('database.connections.sqlite');
        $default = config('database.default');
        $postgres = getenv('MOCKDECK_RUN_CONCURRENCY_POSTGRES') === 'true';
        $database = tempnam(sys_get_temp_dir(), 'mockdeck-run-parallel-');
        $barrier = $database.'.go';
        $server = null;
        $pid = null;
        $clients = [];
        try {
            if ($postgres) {
                self::assertSame('pgsql', $default);
                self::assertSame('mockdeck_run_isolation_test', config('database.connections.pgsql.database'), 'Only the dedicated disposable CI database may be migrated.');
                $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            } else {
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.busy_timeout' => 30000]);
                DB::purge('sqlite');
                $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            }
            $environment = Environment::query()->firstOrFail();
            $scopes = ['ordinary' => null];
            foreach (['first', 'second'] as $label) {
                $owner = app(McpCredentialService::class)->generate($label, ['write'])->credential;
                $scopes[$label] = app(TestRunService::class)->create($owner, $environment, $label);
            }
            $curl = 'curl http://localhost/shared?query=exact -H "Content-Type: application/json" --data \'{"same":"body"}\'';
            $signature = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, false);
            $endpointIds = [];
            foreach ($scopes as $label => $run) {
                $endpoint = MockEndpoint::factory()->create([
                    'test_run_id' => $run?->id, 'method' => 'POST', 'raw_curl' => $curl,
                    'normalized_curl' => $signature->normalized, 'curl_hash' => $signature->hash,
                    'selection_mode' => 'sequence', 'sequence_on_exhaust' => 'not_found',
                    'exclude_headers' => false, 'exclude_auth' => false,
                ]);
                $endpointIds[$label] = $endpoint->id;
                foreach (range(0, 7) as $position) {
                    $endpoint->responses()->create(['sequence_order' => $position,
                        'body' => json_encode(['scope' => $label, 'position' => $position])]);
                }
            }
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $origin = 'http://'.$address;
            $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'PHP_CLI_SERVER_WORKERS' => '8',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MOCK_DASHBOARD_AUTH_ENABLED' => 'false',
                'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false'];
            if (! $postgres) {
                $env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
            }
            $server = new Process(['setsid', PHP_BINARY, '-S', $address, '-t', public_path(), public_path('index.php')], base_path(), $env);
            $server->setTimeout(null);
            $server->start();
            $pid = $server->getPid();
            $ready = false;
            foreach (range(1, 100) as $attempt) {
                if (@file_get_contents($origin.'/up') !== false) {
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            foreach (range(0, 7) as $number) {
                foreach ($scopes as $label => $run) {
                    // Mix header/prefix routing within each run under the same load.
                    $prefix = $run !== null && $number % 2 === 0;
                    $url = $origin.($prefix ? '/_mockdeck/run/'.$run->public_id : '').'/shared?query=exact';
                    $client = new Process([PHP_BINARY, base_path('tests/Support/run-http-client.php'), $barrier, $url,
                        $run !== null && ! $prefix ? $run->public_id : ''], base_path());
                    $client->setTimeout(60);
                    $client->start();
                    $clients[] = [$label, $client];
                }
            }
            touch($barrier);
            $positions = [];
            foreach ($clients as [$label, $client]) {
                $client->wait();
                self::assertTrue($client->isSuccessful(), $client->getErrorOutput());
                $result = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(200, $result['status'], $client->getOutput().$server->getErrorOutput());
                self::assertSame($label, $result['body']['scope']);
                $positions[$label][] = $result['body']['position'];
            }
            foreach ($scopes as $label => $run) {
                sort($positions[$label]);
                self::assertSame(range(0, 7), $positions[$label], 'No duplicate or skipped positions in '.$label);
                $state = EndpointCallState::query()->where('endpoint_id', $endpointIds[$label])->sole();
                self::assertSame($run?->id, $state->test_run_id);
                self::assertSame(8, $state->total_match_count);
                self::assertSame(8, $state->sequence_position);
                self::assertCount(8, $state->recent_call_digests);
            }
            self::assertSame(3, EndpointCallState::query()->count());
        } finally {
            foreach ($clients as [, $client]) {
                if ($client->isRunning()) {
                    $client->stop();
                }
            }
            if ($pid !== null) {
                @posix_kill(-$pid, SIGTERM);
            }
            $server?->stop();
            DB::purge('sqlite');
            config(['database.default' => $default, 'database.connections.sqlite' => $original]);
            @unlink($database);
            @unlink($barrier);
        }
    }
}
