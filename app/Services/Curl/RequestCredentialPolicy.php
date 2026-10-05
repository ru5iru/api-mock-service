<?php

namespace App\Services\Curl;

use Illuminate\Support\Str;

final class RequestCredentialPolicy
{
    public function sensitive(string $name, string $value, bool $query = false): bool
    {
        $names = array_map('strtolower', config('mock.portable_config.'.($query ? 'sensitive_query_keys' : 'sensitive_headers'), []));

        return in_array(strtolower(trim($name)), $names, true)
            && ! Str::contains(Str::lower($value), ['replace_me', 'replace-me', 'redacted']);
    }

    public function maskCurl(string $curl): string
    {
        $sensitiveHeaders = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            config('mock.portable_config.sensitive_headers', []),
        ));

        if ($sensitiveHeaders !== '') {
            $curl = (string) preg_replace_callback(
                '/((?:-H|--header)\s+)([\'\"])(('.$sensitiveHeaders.')\s*:\s*)(.*?)(\2)/i',
                static fn (array $match): string => $match[1].$match[2].$match[3].'REPLACE_ME'.$match[6],
                $curl,
            );
        }

        foreach (config('mock.portable_config.sensitive_query_keys', []) as $key) {
            $curl = (string) preg_replace(
                '/([?&]'.preg_quote((string) $key, '/').'=)[^&\'\"\s]+/i',
                '$1REPLACE_ME',
                $curl,
            );
        }

        return $curl;
    }
}
