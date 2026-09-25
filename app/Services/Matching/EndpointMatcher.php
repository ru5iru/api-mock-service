<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\HashVariant;
use App\Services\Curl\ParsedCurl;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Performs the indexed five-hash lookup first, then a method-scoped canonical
 * string comparison as a safety net for digest bugs or hash-version drift.
 */
final readonly class EndpointMatcher
{
    public function __construct(
        private CurlHasher $hasher,
        private EnvironmentContext $environments,
    ) {}

    public function match(ParsedCurl $request): ?EndpointMatch
    {
        $variants = $this->hasher->variants($request);
        $hashes = array_values(array_unique(array_map(
            static fn ($variant): string => $variant->hash,
            $variants,
        )));

        $candidates = $this->eligibleQuery()
            ->where('enabled', true)
            ->whereIn('curl_hash', $hashes)
            ->get();

        $ranked = $this->rankCandidates($candidates, $variants, 'hash');
        if ($ranked !== null) {
            return new EndpointMatch($ranked['endpoint']->load('responses'), 'hash', $ranked['variant']);
        }

        $normalizedCandidates = array_values(array_unique(array_map(
            static fn ($variant): string => $variant->normalized,
            $variants,
        )));

        $candidates = $this->eligibleQuery()
            ->where('enabled', true)
            ->where('method', strtoupper($request->method))
            ->whereIn('normalized_curl', $normalizedCandidates)
            ->get();

        $ranked = $this->rankCandidates($candidates, $variants, 'normalized');

        return $ranked === null
            ? null
            : new EndpointMatch($ranked['endpoint']->load('responses'), 'fallback', $ranked['variant']);
    }

    private function eligibleQuery(): Builder
    {
        $environmentId = $this->environments->active()->id;

        return MockEndpoint::query()
            ->where('enabled', true)
            ->where(function ($query) use ($environmentId): void {
                $query->whereDoesntHave('environmentOverrides', fn ($override) => $override->whereKey($environmentId))
                    ->orWhereHas('environmentOverrides', fn ($override) => $override
                        ->whereKey($environmentId)
                        ->where('endpoint_environment_overrides.enabled', true));
            });
    }

    /**
     * @param  Collection<int, MockEndpoint>  $candidates
     * @param  array<string, HashVariant>  $variants
     * @return array{endpoint: MockEndpoint, variant: string}|null
     */
    private function rankCandidates($candidates, array $variants, string $comparison): ?array
    {
        $ranked = [];

        foreach ($candidates as $endpoint) {
            $variantName = $this->hasher->variantName(
                $endpoint->exclude_cookies,
                $endpoint->exclude_auth,
                $endpoint->exclude_headers,
            );

            $candidateValue = $comparison === 'hash'
                ? $variants[$variantName]->hash
                : $variants[$variantName]->normalized;
            $storedValue = $comparison === 'hash'
                ? $endpoint->curl_hash
                : $endpoint->normalized_curl;

            if (hash_equals($storedValue, $candidateValue) === false) {
                continue;
            }

            $ranked[] = [
                'endpoint' => $endpoint,
                'variant' => $variantName,
                'priority' => (int) $endpoint->priority,
                'specificity' => MatchPrecedence::specificity($variantName),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return $right['priority'] <=> $left['priority']
                ?: $right['specificity'] <=> $left['specificity']
                ?: $left['endpoint']->id <=> $right['endpoint']->id;
        });

        return $ranked[0] ?? null;
    }
}
