<?php

namespace App\Services\Mcp;

use App\Services\Curl\RequestCredentialPolicy;
use App\Services\Environments\EnvironmentVariableWriter;

/** Defense in depth for metadata. It is never permission to persist raw request payloads. */
final class McpAuditRedactor
{
    public function __construct(private readonly RequestCredentialPolicy $credentials) {}

    public function redact(array|string $value): array|string
    {
        return $this->clean($value, 0);
    }

    private function sensitive(string $name): bool
    {
        return EnvironmentVariableWriter::sensitiveName($name)
            || $this->credentials->sensitive($name, 'value')
            || $this->credentials->sensitive($name, 'value', true);
    }

    private function clean(mixed $value, int $depth): mixed
    {
        if ($depth > 12) {
            return '[TRUNCATED]';
        }
        if ($value instanceof \stdClass) {
            return (object) $this->clean(get_object_vars($value), $depth);
        }
        if (is_array($value)) {
            $label = $value['name'] ?? $value['key'] ?? $value['field_name'] ?? null;
            $sensitiveEntry = is_string($label) && $this->sensitive($label);
            $result = [];
            foreach ($value as $name => $item) {
                // Variable provenance contains identifiers, never resolved values.
                if ($name === 'tokens' && is_array($item) && array_is_list($item)
                    && count(array_filter($item, static fn ($token): bool => is_string($token) && preg_match('/^\{\{\s*\$?[A-Za-z_][A-Za-z0-9_.-]*\s*\}\}$/D', $token) === 1)) === count($item)) {
                    $result[$name] = $item;

                    continue;
                }
                // Import review flags are public metadata, never credential values.
                if (in_array($name, ['contains_secrets', 'is_secret'], true) && is_bool($item)) {
                    $result[$name] = $item;

                    continue;
                }
                $result[$name] = $this->sensitive((string) $name) || ($name === 'value' && $sensitiveEntry)
                    ? '[REDACTED]' : $this->clean($item, $depth + 1);
            }

            return $result;
        }
        if (! is_string($value)) {
            return is_scalar($value) || $value === null ? $value : '[REDACTED]';
        }
        $json = json_decode($value, true);
        if (is_array($json)) {
            return json_encode($this->clean($json, $depth + 1), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $value = $this->credentials->maskCurl($value);
        // The prefixes are credentials issued by this application, regardless of their surrounding field.
        $value = (string) preg_replace('/\bmd[mv]_[a-f0-9]{64}\b/i', '[REDACTED]', $value);
        $value = (string) preg_replace('/\b(Bearer|Basic)\s+[^\s\'"<>]+/i', '$1 [REDACTED]', $value);
        $value = (string) preg_replace('~(https?://)[^/\s:@]+:[^/\s@]*@~i', '$1[REDACTED]@', $value);
        $value = (string) preg_replace_callback('/([?&])([^=&\s]+)=([^&\s\'"<>]*)/', function (array $match): string {
            return $this->sensitive(urldecode($match[2])) ? $match[1].$match[2].'=[REDACTED]' : $match[0];
        }, $value);
        $value = (string) preg_replace_callback('/(^|[\r\n])([^:\r\n]+):([^\r\n]*)/', function (array $match): string {
            return $this->sensitive(trim($match[2])) ? $match[1].$match[2].': [REDACTED]' : $match[0];
        }, $value);

        return strlen($value) > 4096 ? substr($value, 0, 4096).'[TRUNCATED]' : $value;
    }
}
