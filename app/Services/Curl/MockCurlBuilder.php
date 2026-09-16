<?php

namespace App\Services\Curl;

use InvalidArgumentException;

final class MockCurlBuilder
{
    public function build(ParsedCurl $request): string
    {
        $parts = parse_url($request->url);
        if ($parts === false) {
            throw new InvalidArgumentException('The request URL is invalid.');
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $path = $parts['path'] ?? '/';
        $url = $baseUrl.($path === '' ? '/' : $path);
        if (array_key_exists('query', $parts) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }

        $lines = ['curl '.$this->quote($url)];
        $curlInfersPost = $request->body !== '' && $request->method === 'POST';
        if ($request->method !== 'GET' && ! $curlInfersPost) {
            $lines[] = '  --request '.$this->quote($request->method);
        }
        foreach ($request->headers as $header) {
            $lines[] = '  -H '.$this->quote($header['name'].': '.$header['value']);
        }
        if ($request->body !== '') {
            $lines[] = '  --data '.$this->quote($request->body);
        }

        return implode(" \\\n", $lines);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
