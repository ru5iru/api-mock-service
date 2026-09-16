<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\ParsedCurl;

/**
 * Performs the indexed five-hash lookup first, then a method-scoped canonical
 * string comparison as a safety net for digest bugs or hash-version drift.
 */
final readonly class EndpointMatcher
{
    public function __construct(private CurlHasher $hasher) {}

    public function match(ParsedCurl $request): ?EndpointMatch
    {
        $variants = $this->hasher->variants($request);
        $hashes = array_values(array_unique(array_map(
            static fn ($variant): string => $variant->hash,
            $variants,
        )));

        $endpoint = MockEndpoint::query()
            ->with('responses')
            ->whereIn('curl_hash', $hashes)
            ->orderBy('id')
            ->first();

        if ($endpoint !== null) {
            $expectedVariant = $this->hasher->variantName(
                $endpoint->exclude_cookies,
                $endpoint->exclude_auth,
                $endpoint->exclude_headers,
            );

            $matchedVariant = ($variants[$expectedVariant]->hash ?? null) === $endpoint->curl_hash
                ? $expectedVariant
                : collect($variants)->first(fn ($variant) => $variant->hash === $endpoint->curl_hash)?->name;

            return new EndpointMatch($endpoint, 'hash', $matchedVariant);
        }

        $normalizedCandidates = array_values(array_unique(array_map(
            static fn ($variant): string => $variant->normalized,
            $variants,
        )));

        $endpoint = MockEndpoint::query()
            ->with('responses')
            ->where('method', strtoupper($request->method))
            ->whereIn('normalized_curl', $normalizedCandidates)
            ->orderBy('id')
            ->first();

        return $endpoint === null ? null : new EndpointMatch($endpoint, 'fallback', null);
    }
}
