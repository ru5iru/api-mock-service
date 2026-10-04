<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\ParsedCurl;
use InvalidArgumentException;

/**
 * Hybrid exact-request matching: only opted-in path segments are wildcards.
 * Method, query, retained headers and body still use the canonical exact hash.
 * V6 signs the configured pattern itself, never the captured instance path.
 */
final readonly class PatternPathMatcher implements RequestMatcher
{
    public function __construct(private CurlHasher $hasher, private PathPattern $patterns) {}

    public function matchEndpoint(MockEndpoint $endpoint, ParsedCurl $request): ?EndpointMatch
    {
        $captures = [];
        try {
            if ($endpoint->path_pattern_enabled) {
                $captures = $this->patterns->capture($endpoint->requestPath(), (string) (parse_url($request->url, PHP_URL_PATH) ?: '/'));
                if ($captures === null) {
                    return null;
                }
                $request = new ParsedCurl($request->method, $this->patterns->replaceUrlPath($request->url, $endpoint->requestPath()), $request->headers, $request->body);
            }
            $candidate = $this->hasher->forOptions($request, $endpoint->exclude_cookies, $endpoint->exclude_auth, $endpoint->exclude_headers,
                $endpoint->excluded_query_params ?? [], $endpoint->excluded_headers ?? [], (bool) $endpoint->path_pattern_enabled);
        } catch (InvalidArgumentException) {
            return null; // Corrupt imported configuration cannot fail otherwise matching traffic.
        }
        if (hash_equals((string) $endpoint->curl_hash, $candidate->hash)) {
            return new EndpointMatch($endpoint, 'hash', $candidate->name, $captures);
        }
        if (hash_equals((string) $endpoint->normalized_curl, $candidate->normalized)) {
            return new EndpointMatch($endpoint, 'fallback', $candidate->name, $captures);
        }

        return null;
    }
}
