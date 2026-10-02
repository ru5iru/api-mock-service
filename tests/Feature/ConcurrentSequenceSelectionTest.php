<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate, disposable on-disk database enables genuinely parallel PHP processes. */
final class ConcurrentSequenceSelectionTest extends TestCase
{
    public function test_parallel_first_calls_allocate_every_sequence_position_once(): void
    {
        $this->assertParallelCalls(false);
    }

    public function test_parallel_http_invocations_return_each_configured_response_once(): void
    {
        $this->assertParallelCalls(true);
    }

    private function assertParallelCalls(bool $http): void
    {
        if (! function_exists('proc_open') || ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Requires process spawning and PDO SQLite.');
        }
        $database = tempnam(sys_get_temp_dir(), 'mockdeck-concurrency-');
        $original = config('database.connections.sqlite');
        $default = config('database.default');
        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.busy_timeout' => 30000]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $curl = "curl 'https://api.example.test/wave1-concurrent'";
            $signature = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
            $endpoint = MockEndpoint::factory()->create([
                'selection_mode' => 'sequence',
                'sequence_on_exhaust' => 'not_found',
                'raw_curl' => $curl,
                'normalized_curl' => $signature->normalized,
                'curl_hash' => $signature->hash,
            ]);
            $environment = Environment::query()->firstOrFail();
            foreach (range(0, 15) as $position) {
                $endpoint->responses()->create(['sequence_order' => $position, 'body' => json_encode(['order' => $position]), 'headers' => ['Content-Type' => 'application/json']]);
            }
            $workers = [];
            foreach (range(1, 16) as $call) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/sequence-worker.php'), $database, (string) $endpoint->id, (string) $environment->id, $http ? 'http' : 'service'], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false']);
                $process->setTimeout(60);
                $process->start();
                $workers[] = $process;
            }
            $positions = [];
            foreach ($workers as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                if ($http) {
                    self::assertSame(200, $result['status'], $process->getOutput());
                    self::assertNotEmpty($result['request_id']);
                } else {
                    self::assertSame($result['position'], $result['order']);
                }
                $positions[] = $result['order'];
            }
            sort($positions);
            self::assertSame(range(0, 15), $positions);
            self::assertSame(16, EndpointCallState::query()->sole()->sequence_position);
            self::assertSame(16, EndpointCallState::query()->sole()->total_match_count);
        } finally {
            DB::purge('sqlite');
            config(['database.default' => $default, 'database.connections.sqlite' => $original]);
            @unlink($database);
        }
    }
}
