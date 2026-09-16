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
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return new ParsedCurl(
            strtoupper($request->method()),
            $request->fullUrl(),
            $headers,
            (string) $request->getContent(),
        );
    }
}
