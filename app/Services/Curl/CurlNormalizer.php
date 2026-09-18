<?php

namespace App\Services\Curl;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Produces the single canonical representation used by both saved curls and
 * incoming requests. Keeping this logic shared is the core matching invariant.
 */
final class CurlNormalizer
{
    public function normalize(
        ParsedCurl $request,
        bool $excludeCookies = false,
        bool $excludeAuth = false,
        bool $excludeHeaders = false,
    ): NormalizedCurl {
        $contentType = $this->contentType($request->headers);
        $headers = $excludeHeaders
            ? []
            : $this->canonicalHeaders($request->headers, $excludeCookies, $excludeAuth);

        $lines = [strtoupper($request->method), $this->canonicalUrl($request->url)];
        foreach ($headers as $header) {
            $lines[] = $header['name'].':'.$header['value'];
        }

        $body = $this->canonicalBody($request->body, $contentType);
        $normalized = implode("\n", $lines)."\n\n".$body;

        return new NormalizedCurl($normalized, hash('sha256', $normalized));
    }

    /**
     * @param  list<array{name: string, value: string}>  $headers
     * @return list<array{name: string, value: string}>
     */
    private function canonicalHeaders(array $headers, bool $excludeCookies, bool $excludeAuth): array
    {
        $authNames = array_map('strtolower', config('mock.auth_header_names', ['authorization']));
        $transportNames = array_map('strtolower', config('mock.transport_header_names', []));
        $canonical = [];

        foreach ($headers as $header) {
            $name = strtolower(trim($header['name']));

            if (
                $name === ''
                || in_array($name, $transportNames, true)
                || ($excludeCookies && $name === 'cookie')
            ) {
                continue;
            }

            if ($excludeAuth && in_array($name, $authNames, true)) {
                continue;
            }

            $canonical[] = ['name' => $name, 'value' => trim($header['value'])];
        }

        usort($canonical, static fn (array $left, array $right): int => [$left['name'], $left['value']] <=> [$right['name'], $right['value']]);

        return $canonical;
    }

    private function canonicalUrl(string $url): string
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

        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }
        $canonical = $path;

        if (array_key_exists('query', $parts) && $parts['query'] !== '') {
            $canonical .= '?'.$this->canonicalQuery($parts['query']);
        }

        return $canonical;
    }

    private function canonicalQuery(string $query): string
    {
        $parameters = [];

        foreach (explode('&', $query) as $position => $parameter) {
            $hasEquals = str_contains($parameter, '=');
            [$rawKey, $rawValue] = array_pad(explode('=', $parameter, 2), 2, '');
            $decodedKey = urldecode($rawKey);
            $decodedValue = urldecode($rawValue);
            $parameters[] = [
                'key' => rawurlencode($decodedKey),
                'value' => rawurlencode($decodedValue),
                'sort_key' => $decodedKey,
                'position' => $position,
                'has_equals' => $hasEquals,
            ];
        }

        usort($parameters, static fn (array $left, array $right): int => [$left['sort_key'], $left['position']] <=> [$right['sort_key'], $right['position']]);

        return implode('&', array_map(
            static fn (array $parameter): string => $parameter['key']
                .($parameter['has_equals'] ? '='.$parameter['value'] : ''),
            $parameters,
        ));
    }

    private function canonicalBody(string $body, ?string $contentType): string
    {
        $body = trim($body);

        if ($body === '' || ! $this->isJsonContentType($contentType)) {
            return $body;
        }

        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);

            return json_encode(
                $this->sortJson($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            return $body;
        }
    }

    private function sortJson(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass;
            foreach ($properties as $key => $property) {
                $sorted->{$key} = $this->sortJson($property);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map($this->sortJson(...), $value);
        }

        return $value;
    }

    /** @param list<array{name: string, value: string}> $headers */
    private function contentType(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (strcasecmp($header['name'], 'Content-Type') === 0) {
                return strtolower($header['value']);
            }
        }

        return null;
    }

    private function isJsonContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $mediaType = trim(explode(';', $contentType, 2)[0]);

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
