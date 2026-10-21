<?php

namespace App\Services\Mcp;

use App\Models\TestRun;

final class McpOutput
{
    public function safe(array $data, ?TestRun $run = null): array
    {
        $data = app(McpAuditRedactor::class)->redact($data);
        $secrets = $run?->environment->variables()->where('is_secret', true)->get()->pluck('value')->filter(fn ($value) => is_string($value) && $value !== '')->all() ?? [];
        $clean = function ($value) use (&$clean, $secrets) {
            if ($value instanceof \stdClass) {
                return (object) array_map($clean, get_object_vars($value));
            }
            if (is_array($value)) {
                return array_map($clean, $value);
            }

            return is_string($value) && $secrets !== [] ? str_replace($secrets, '[REDACTED]', $value) : $value;
        };

        return $clean($data);
    }
}
