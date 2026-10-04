<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ConcurrentVerificationTest extends TestCase
{
    public function test_parallel_first_http_matches_keep_every_counter_and_digest(): void
    {
        if (! function_exists('proc_open') || ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Requires process spawning and PDO SQLite.');
        }
        $database = tempnam(sys_get_temp_dir(), 'mockdeck-verify-concurrency-');
        $original = config('database.connections.sqlite');
        $default = config('database.default');
        $workers = [];
        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.busy_timeout' => 30000]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $curl = "curl 'https://api.example.test/verify-concurrent'";
            $signature = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
            $endpoint = MockEndpoint::factory()->create(['raw_curl' => $curl, 'normalized_curl' => $signature->normalized, 'curl_hash' => $signature->hash]);
            $endpoint->responses()->create(['body' => 'ok']);
            foreach (range(1, 12) as $number) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/verification-worker.php'), $database, (string) $number], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false']);
                $process->setTimeout(60);
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                self::assertSame(['status' => 200, 'body' => 'ok'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            }
            $state = EndpointCallState::query()->sole();
            self::assertSame(12, $state->total_match_count);
            self::assertCount(12, $state->recent_call_digests);
            $numbers = array_map(fn (array $call): int => (int) $call['header_digest']['fields']['x-call-number']['value'], $state->recent_call_digests);
            sort($numbers);
            self::assertSame(range(1, 12), $numbers);
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::purge('sqlite');
            config(['database.default' => $default, 'database.connections.sqlite' => $original]);
            @unlink($database);
        }
    }
}
