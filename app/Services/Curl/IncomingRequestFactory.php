<?php

namespace App\Services\Curl;

use Illuminate\Http\Request;

/**
 * Reconstructs a live Laravel request into the exact same shape as CurlParser.
 */
final class IncomingRequestFactory
{
    public function fromRequest(Request $request): ParsedCurl
    {
        $originalUrlHeader = strtolower((string) config('mock.original_url_header', 'X-Mock-Original-Url'));
        $url = $request->headers->get($originalUrlHeader) ?: $request->fullUrl();
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (strtolower($name) === $originalUrlHeader) {
                continue;
            }

            foreach ($values as $value) {
                $headers[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return new ParsedCurl(
            strtoupper($request->method()),
            (string) $url,
            $headers,
            (string) $request->getContent(),
        );
    }
}
