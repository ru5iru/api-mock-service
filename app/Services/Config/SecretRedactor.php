<?php

namespace App\Services\Config;

use App\Services\Curl\ParsedCurl;
use InvalidArgumentException;

final class SecretRedactor
{
    public function redact(ParsedCurl $request): RedactedCurl
    {
        $sensitiveHeaders = array_map('strtolower', config('mock.portable_config.sensitive_headers', []));
        $headers = [];
        $changed = false;

        foreach ($request->headers as $header) {
            if (in_array(strtolower(trim($header['name'])), $sensitiveHeaders, true)) {
                $changed = true;

                continue;
            }

            $headers[] = $header;
        }

        [$url, $urlChanged] = $this->redactUrl($request->url);

        return new RedactedCurl(
            $this->format(new ParsedCurl($request->method, $url, $headers, $request->body)),
            $changed || $urlChanged,
        );
    }

    /** @return array{string, bool} */
    private function redactUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The request URL must be an absolute HTTP or HTTPS URL.');
        }

        $sensitiveKeys = array_map('strtolower', config('mock.portable_config.sensitive_query_keys', []));
        $queryParts = [];
        $changed = false;

        foreach (explode('&', (string) ($parts['query'] ?? '')) as $parameter) {
            if ($parameter === '') {
                continue;
            }

            [$key] = explode('=', $parameter, 2);
            if (in_array(strtolower(urldecode($key)), $sensitiveKeys, true)) {
                $changed = true;

                continue;
            }

            $queryParts[] = $parameter;
        }

        $authority = strtolower($parts['scheme']).'://'.$parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        $redactedUrl = $authority.($parts['path'] ?? '/');
        if ($queryParts !== []) {
            $redactedUrl .= '?'.implode('&', $queryParts);
        }

        return [$redactedUrl, $changed];
    }

    private function format(ParsedCurl $request): string
    {
        $parts = [
            'curl',
            '--request',
            $this->quote(strtoupper($request->method)),
            $this->quote($request->url),
        ];

        foreach ($request->headers as $header) {
            $parts[] = '--header';
            $parts[] = $this->quote($header['name'].': '.$header['value']);
        }

        if ($request->body !== '') {
            $parts[] = '--data-raw';
            $parts[] = $this->quote($request->body);
        }

        return implode(' ', $parts);
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
