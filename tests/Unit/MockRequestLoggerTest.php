<?php

namespace Tests\Unit;

use App\Services\Logging\MockRequestLogger;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class MockRequestLoggerTest extends TestCase
{
    public function test_logging_failure_does_not_break_the_mock_response_path(): void
    {
        Log::shouldReceive('channel')->once()->andThrow(new RuntimeException('disk unavailable'));

        (new MockRequestLogger)->write([
            'request_id' => 'test-request',
            'match_tier' => 'hash',
        ]);

        self::assertTrue(true);
    }
}
