<?php

namespace App\Services\Curl;

/** Serialize parsed request data without executing a shell. Shared by format adapters. */
final class CurlFormatter
{
    public function format(ParsedCurl $request): string
    {
        $parts = ['curl', '--request', $this->quote(strtoupper($request->method)), $this->quote($request->url)];
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
