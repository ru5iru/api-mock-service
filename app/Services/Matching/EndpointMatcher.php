<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\ParsedCurl;
use App\Services\Environments\EnvironmentContext;

final readonly class EndpointMatcher
{
    public function __construct(
        private ExactHashMatcher $exact,
        private PatternPathMatcher $patterns,
        private CurlHasher $hasher,
        private EnvironmentContext $environments,
    ) {}

    public function match(ParsedCurl $request): ?EndpointMatch
    {
        // Keep the existing indexed V1–V5 lookup and fallback behavior intact.
        $legacy = $this->exact->match($request);
        $matches = $legacy === null ? [] : [$legacy];
        $environmentId = $this->environments->active()->id;
        $candidates = MockEndpoint::query()->where('enabled', true)
            ->where('method', strtoupper($request->method))
            ->where(function ($query) use ($environmentId): void {
                $query->whereDoesntHave('environmentOverrides', fn ($override) => $override->whereKey($environmentId))
                    ->orWhereHas('environmentOverrides', fn ($override) => $override->whereKey($environmentId)->where('endpoint_environment_overrides.enabled', true));
            })->get();
        foreach ($candidates as $endpoint) {
            if ($endpoint->hasFieldMatching() && ($match = $this->patterns->matchEndpoint($endpoint, $request)) !== null) {
                $matches[] = $match;
            }
        }
        usort($matches, function (EndpointMatch $left, EndpointMatch $right): int {
            // Priority remains first; a literal path wins over a wildcard at equal priority.
            return $right->endpoint->priority <=> $left->endpoint->priority
                ?: (int) $left->endpoint->path_pattern_enabled <=> (int) $right->endpoint->path_pattern_enabled
                ?: $this->specificity($right->endpoint) <=> $this->specificity($left->endpoint)
                ?: $left->endpoint->id <=> $right->endpoint->id;
        });
        $winner = $matches[0] ?? null;
        $winner?->endpoint->loadMissing('responses');

        return $winner;
    }

    private function specificity(MockEndpoint $endpoint): int
    {
        return MatchPrecedence::specificity($this->hasher->variantName($endpoint->exclude_cookies, $endpoint->exclude_auth, $endpoint->exclude_headers));
    }
}
