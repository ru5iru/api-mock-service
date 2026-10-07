<?php

namespace App\Services\Response;

/** Saved examples describe content; the current HTTP server owns wire framing. */
final class ResponseHeaderPolicy
{
    public function validName(string $name): bool
    {
        // HTTP/2 pseudo-headers (for example :status) are protocol metadata,
        // not HTTP response fields. PHP-FPM must never emit them to nginx.
        return preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', $name) === 1;
    }

    public function forServing(array $headers, bool $decodedPostmanBody = false): array
    {
        $excluded = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length', 'status'];
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Connection') === 0) {
                $excluded = [...$excluded, ...array_map(static fn (string $token): string => strtolower(trim($token)), explode(',', (string) $value))];
            }
        }
        // Postman exports decoded example text, not the gzip/br bytes sent by
        // the original server. Replaying that server's encoding is incorrect.
        if ($decodedPostmanBody) {
            $excluded[] = 'content-encoding';
        }

        // Status is also a CGI control field; the configured status_code owns it.
        // This protects already-imported responses as well as new imports.
        return array_filter($headers, fn (string|int $name): bool => $this->validName((string) $name) && ! in_array(strtolower((string) $name), $excluded, true), ARRAY_FILTER_USE_KEY);
    }
}
