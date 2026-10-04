<?php

namespace App\Services\Response;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** The one condition grammar used by response rules and verification assertions. */
final class RequestPredicateEvaluator
{
    public function matches(array $condition, Request $request): bool
    {
        $name = $condition['field_name'];
        [$exists, $actual] = match ($condition['field_type']) {
            'header' => [$request->headers->has($name), $request->header($name)],
            'query' => [array_key_exists($name, $request->query()), $request->query($name)],
            'body_json_path' => $this->jsonValue($request, $name),
            default => [false, null],
        };

        return $this->compare($condition, $exists, $actual);
    }

    public function compare(array $condition, bool $exists, mixed $actual): bool
    {
        if ($condition['operator'] === 'exists') {
            return $exists;
        }
        if (! $exists || ! is_scalar($actual)) {
            return false;
        }
        $actual = $this->scalarString($actual);
        $value = (string) ($condition['value'] ?? '');

        return match ($condition['operator']) {
            'equals' => $actual === $value,
            'contains' => str_contains($actual, $value),
            'regex' => $this->regexMatches($value, $actual),
            default => false,
        };
    }

    public function scalarString(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
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
