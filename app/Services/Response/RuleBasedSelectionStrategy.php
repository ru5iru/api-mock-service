<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\ResponseRule;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class RuleBasedSelectionStrategy implements SelectionStrategyInterface
{
    public function select(MockEndpoint $endpoint, Collection $responses, Request $request, EndpointCallState $state): SelectionResult
    {
        $ordered = $responses->filter(fn (MockResponse $response) => ! $response->is_default && $response->rules->isNotEmpty())
            ->sort(fn (MockResponse $a, MockResponse $b) => [$a->rules->min('priority'), -$a->weight, $a->uuid] <=> [$b->rules->min('priority'), -$b->weight, $b->uuid]);
        foreach ($ordered as $response) {
            if ($response->rules->every(fn (ResponseRule $rule) => $this->matches($rule, $request))) {
                return new SelectionResult($response);
            }
        }
        $fallback = $responses->first(fn (MockResponse $response) => $response->is_default);

        return new SelectionResult($fallback, $fallback ? 'selected' : 'rule_no_default');
    }

    private function matches(ResponseRule $rule, Request $request): bool
    {
        $name = $rule->field_name;
        [$exists, $actual] = match ($rule->field_type) {
            'header' => [$request->headers->has($name), $request->header($name)],
            'query' => [array_key_exists($name, $request->query()), $request->query($name)],
            'body_json_path' => $this->jsonValue($request, $name),
            default => [false, null],
        };
        if ($rule->operator === 'exists') {
            return $exists;
        }
        if (! $exists || ! is_scalar($actual)) {
            return false;
        }
        $actual = is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual;
        $value = (string) $rule->value;

        return match ($rule->operator) {
            'equals' => $actual === $value,
            'contains' => str_contains($actual, $value),
            'regex' => $this->regexMatches($value, $actual),
            default => false,
        };
    }

    private function regexMatches(string $pattern, string $actual): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) min((int) $previous, 100000));
        try {
            return @preg_match($pattern, $actual) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    private function jsonValue(Request $request, string $path): array
    {
        $json = json_decode($request->getContent(), true);
        if (! is_array($json)) {
            return [false, null];
        }

        return [Arr::has($json, $path), Arr::get($json, $path)];
    }
}
