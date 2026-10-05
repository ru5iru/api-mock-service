<?php

namespace App\Services\Curl;

use InvalidArgumentException;

/** The URL validation previously embedded in CurlNormalizer, shared by import adapters. */
final class RequestUrl
{
    public function parse(string $url): array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The request URL must be an absolute HTTP or HTTPS URL.');
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The request URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials must be supplied as headers, not embedded in the URL.');
        }

        return $parts;
    }
}
