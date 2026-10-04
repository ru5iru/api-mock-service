<?php

namespace App\Services\Verification;

use App\Models\EndpointCallState;
use App\Services\Response\RequestPredicateEvaluator;

final class CallAssertionService
{
    public function __construct(private readonly RequestPredicateEvaluator $predicates) {}

    public function evaluate(?EndpointCallState $state, array $conditions, string $comparator, int $expected): array
    {
        $total = $state?->total_match_count ?? 0;
        $recent = $state?->recent_call_digests ?? [];
        $unretained = max(0, $total - count($recent));
        $known = 0;
        $uncertain = 0;
        if ($conditions === []) {
            $minimum = $maximum = $total;
        } else {
            foreach ($recent as $call) {
                $match = $this->matches($call, $conditions);
                $known += $match === true ? 1 : 0;
                $uncertain += $match === null ? 1 : 0;
            }
            $minimum = $known;
            $maximum = $known + $uncertain + $unretained;
        }
        $passed = match ($comparator) {
            'equals' => $minimum === $maximum && $minimum === $expected,
            'at_least' => $minimum >= $expected,
            'at_most' => $maximum <= $expected,
        };
        $failed = match ($comparator) {
            'equals' => $expected < $minimum || $expected > $maximum,
            'at_least' => $maximum < $expected,
            'at_most' => $minimum > $expected,
        };
        $verdict = $passed ? 'passed' : ($failed ? 'failed' : 'indeterminate');
        $actual = $minimum === $maximum ? $minimum : null;
        $count = $actual === null ? "between {$minimum} and {$maximum}" : (string) $actual;
        $message = "Expected {$comparator} {$expected} matching calls since reset; observed {$count}.";
        if ($verdict === 'indeterminate') {
            $message .= ' Cannot determine the assertion: older calls or required field values are not retained. Reset before the test and assert within 20 calls, or use an unfiltered count / exact-value predicate.';
        }

        return [
            'passed' => $passed,
            'actual_count' => $actual,
            'message' => $message,
            'verdict' => $verdict,
            'scope' => 'since_reset',
            'count_bounds' => ['minimum' => $minimum, 'maximum' => $maximum],
            'window' => [
                'total_match_count' => $total,
                'retained_calls' => count($recent),
                'unretained_calls' => $unretained,
                'undecidable_retained_calls' => $uncertain,
                'capacity' => CallDigestRecorder::CAPACITY,
            ],
        ];
    }

    /** Three-valued AND: a known nonmatch wins even if another field was omitted. */
    private function matches(array $call, array $conditions): ?bool
    {
        $unknown = false;
        foreach ($conditions as $condition) {
            $result = $this->matchesCondition($call, $condition);
            if ($result === false) {
                return false;
            }
            $unknown = $unknown || $result === null;
        }

        return $unknown ? null : true;
    }

    private function matchesCondition(array $call, array $condition): ?bool
    {
        $key = match ($condition['field_type']) {
            'header' => 'header_digest',
            'query' => 'query_digest',
            'body_json_path' => 'body_digest_or_snippet',
        };
        $projection = $call[$key] ?? [];
        $name = $condition['field_type'] === 'header' ? strtolower($condition['field_name']) : $condition['field_name'];
        if (! array_key_exists($name, $projection['fields'] ?? [])) {
            return ($projection['complete'] ?? false) ? false : null;
        }
        $field = $projection['fields'][$name];
        if ($condition['operator'] === 'exists') {
            return true;
        }
        if (! ($field['scalar'] ?? false)) {
            return false;
        }
        if (array_key_exists('value', $field)) {
            return $this->predicates->compare($condition, true, $field['value']);
        }
        if ($condition['operator'] === 'equals' && isset($field['sha256'])) {
            return hash_equals($field['sha256'], hash('sha256', (string) $condition['value']));
        }

        return null;
    }
}
