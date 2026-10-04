<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\ResponseRule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class RuleBasedSelectionStrategy implements SelectionStrategyInterface
{
    public function __construct(private readonly RequestPredicateEvaluator $predicates) {}

    public function select(MockEndpoint $endpoint, Collection $responses, Request $request, EndpointCallState $state): SelectionResult
    {
        $ordered = $responses->filter(fn (MockResponse $response) => ! $response->is_default && $response->rules->isNotEmpty())
            ->sort(fn (MockResponse $a, MockResponse $b) => [$a->rules->min('priority'), -$a->weight, $a->uuid] <=> [$b->rules->min('priority'), -$b->weight, $b->uuid]);
        foreach ($ordered as $response) {
            if ($response->rules->every(fn (ResponseRule $rule) => $this->predicates->matches($rule->getAttributes(), $request))) {
                return new SelectionResult($response);
            }
        }
        $fallback = $responses->first(fn (MockResponse $response) => $response->is_default);

        return new SelectionResult($fallback, $fallback ? 'selected' : 'rule_no_default');
    }
}
