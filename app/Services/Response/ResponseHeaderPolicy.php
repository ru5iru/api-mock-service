<?php

namespace App\Services\Response;

/** Saved examples describe content; the current HTTP server owns wire framing. */
final class ResponseHeaderPolicy
{
    public function forServing(array $headers, bool $decodedPostmanBody = false): array
    {
        $excluded = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length'];
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

        return array_filter($headers, static fn (string|int $name): bool => ! in_array(strtolower((string) $name), $excluded, true), ARRAY_FILTER_USE_KEY);
    }
}
