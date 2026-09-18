<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes exactly one structured event for every completed mock invocation.
 */
final class MockRequestLogger
{
    /** @param array<string, mixed> $context */
    public function write(array $context): void
    {
        try {
            $level = ($context['match_tier'] ?? null) === 'fallback' ? 'warning' : 'info';
            Log::channel('mock_requests')->log($level, 'mock_request', $context);
        } catch (Throwable $exception) {
            error_log('MockDeck request logging failed: '.$exception::class);
        }
    }
}
