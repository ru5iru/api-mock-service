<?php

namespace App\Services\Response;

use App\Models\MockResponse;

/** Primary-response effects only: selection and match counters are deliberately independent. */
final class FaultInjectionService
{
    public function decide(MockResponse $response): ?FaultPlan
    {
        if (! $response->fault_enabled) {
            return null;
        }

        $probability = max(0, min(100, (int) $response->fault_probability));
        if ($probability === 0 || ($probability < 100 && random_int(1, 100) > $probability)) {
            return null;
        }

        $type = (string) $response->fault_type;
        if (! in_array($type, FaultConfigurationValidator::TYPES, true)) {
            return null;
        }
        $delay = 0;
        if (in_array($type, ['delay', 'timeout'], true)) {
            $min = max(0, min(FaultConfigurationValidator::MAX_DELAY_MS, (int) $response->fault_delay_ms_min));
            $max = max($min, min(FaultConfigurationValidator::MAX_DELAY_MS, (int) ($response->fault_delay_ms_max ?? $min)));
            $delay = $min === $max ? $min : random_int($min, $max);
        }

        return new FaultPlan($type, $delay);
    }

    public function pause(?FaultPlan $plan): void
    {
        // nginx/PHP-FPM cannot reset a TCP socket or guarantee an indefinite hang.
        // Timeout intentionally approximates a slow upstream with a bounded delay.
        if ($plan !== null && $plan->delayMs > 0) {
            usleep($plan->delayMs * 1000);
        }
    }

    /** Apply after template rendering. Do not change the declared Content-Type. */
    public function transformBody(string $body, ?FaultPlan $plan, string $contentType): string
    {
        if ($plan === null) {
            return $body;
        }
        if ($plan->type === 'truncated_body') {
            // Byte truncation is deliberate, including a possible split UTF-8 sequence.
            return substr($body, 0, intdiv(strlen($body), 2));
        }
        if ($plan->type === 'malformed_body') {
            $type = strtolower(trim(explode(';', $contentType, 2)[0]));
            if ($type === 'application/json' || str_ends_with($type, '+json')) {
                return '{"mockdeck_fault":';
            }
            if (in_array($type, ['application/xml', 'text/xml'], true) || str_ends_with($type, '+xml')) {
                return '<mockdeck-fault>';
            }

            // Save/import reject unsupported formats; resilient to out-of-band edits.
            return substr($body, 0, intdiv(strlen($body), 2));
        }

        return $body;
    }
}
