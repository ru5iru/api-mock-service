<?php

namespace App\Services\Curl;

use InvalidArgumentException;

/** Builds a shell-safe invocation curl that targets the configured mock host. */
final class MockCurlBuilder
{
    public function build(ParsedCurl $request): string
    {
        $requestParts = $this->absoluteHttpUrlParts($request->url, 'The request URL');
        $baseUrl = rtrim($this->mockBaseUrl(), '/');
        $path = $requestParts['path'] ?? '/';
        $url = $baseUrl.($path === '' ? '/' : $path);

        if (array_key_exists('query', $requestParts) && $requestParts['query'] !== '') {
            $url .= '?'.$requestParts['query'];
        }

        $method = strtoupper($request->method);
        $lines = ['curl '.$this->quote($url)];
        $curlInfersPost = $request->body !== '' && $method === 'POST';

        if ($method !== 'GET' && ! $curlInfersPost) {
            $lines[] = '  --request '.$this->quote($method);
        }

        foreach ($request->headers as $header) {
            $lines[] = '  --header '.$this->quote($header['name'].': '.$header['value']);
        }

        if ($request->body !== '') {
            $lines[] = '  --data '.$this->quote($request->body);
        }

        return implode(" \\\n", $lines);
    }

    private function mockBaseUrl(): string
    {
        $baseUrl = trim((string) config('app.url'));
        $parts = $this->absoluteHttpUrlParts($baseUrl, 'APP_URL');

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('APP_URL must not contain credentials, a query, or a fragment.');
        }

        return $baseUrl;
    }

    /** @return array<string, int|string> */
    private function absoluteHttpUrlParts(string $url, string $label): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("{$label} must be an absolute HTTP or HTTPS URL.");
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException("{$label} must use HTTP or HTTPS.");
        }

        return $parts;
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
