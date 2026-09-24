<?php

namespace App\Services\Templates;

use JsonException;
use stdClass;

final class TemplateCompiler
{
    /** @var list<TemplateIssue> */
    private array $issues = [];

    private int $nodes = 0;

    private string $source = '';

    public function __construct(private readonly FakerMethodCatalog $catalog)
    {
    }

    public function compile(string $source, string $locale = 'en'): TemplateValidationResult
    {
        $this->issues = [];
        $this->nodes = 0;
        $this->source = $source;

        if (strlen($source) > (int) config('mock.templates.max_template_bytes', 262144)) {
            $this->issue('error', 'LIMIT_EXCEEDED', '/', 'The template exceeds the configured 256 KB limit.');

            return new TemplateValidationResult(null, $this->issues);
        }

        try {
            $root = json_decode($source, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->issue('error', 'INVALID_JSON', '/', 'Template must be valid JSON: '.$exception->getMessage());

            return new TemplateValidationResult(null, $this->issues);
        }

        if (! is_array($root) && ! $root instanceof stdClass) {
            $this->issue('error', 'INVALID_JSON', '/', 'The template root must be an object, array, or $repeat directive.');
        } else {
            $this->walk($root, '/', 0, false, false);
        }

        try {
            $this->catalog->faker($locale);
        } catch (\InvalidArgumentException $exception) {
            $this->issue('error', 'BAD_ARGS', '/', $exception->getMessage());
        }

        $compiled = $this->hasErrors() ? null : new CompiledTemplate($source, $root, $this->issues);

        return new TemplateValidationResult($compiled, $this->issues);
    }

    private function walk(mixed $node, string $path, int $depth, bool $insideRepeat, bool $literal): void
    {
        $this->nodes++;
        if ($this->nodes > (int) config('mock.templates.max_nodes', 10000)) {
            $this->issue('error', 'LIMIT_EXCEEDED', $path, 'The template exceeds the configured 10,000-node limit.');

            return;
        }

        if ($depth > (int) config('mock.templates.max_depth', 12)) {
            $this->issue('error', 'LIMIT_EXCEEDED', $path, 'The template exceeds the configured depth limit of 12.');

            return;
        }

        if ($literal) {
            if ($node instanceof stdClass) {
                foreach (get_object_vars($node) as $key => $value) {
                    $this->walk($value, $this->pointer($path, (string) $key), $depth + 1, $insideRepeat, true);
                }
            } elseif (is_array($node)) {
                foreach ($node as $index => $value) {
                    $this->walk($value, $this->pointer($path, (string) $index), $depth + 1, $insideRepeat, true);
                }
            }

            return;
        }

        if (is_string($node)) {
            $this->validateString($node, $path, $insideRepeat);

            return;
        }

        if (is_array($node)) {
            foreach ($node as $index => $value) {
                $this->walk($value, $this->pointer($path, (string) $index), $depth + 1, $insideRepeat, false);
            }

            return;
        }

        if (! $node instanceof stdClass) {
            return;
        }

        $values = get_object_vars($node);
        $reserved = array_values(array_filter(array_keys($values), static fn (string $key): bool => str_starts_with($key, '$')));
        if ($reserved === []) {
            foreach ($values as $key => $value) {
                $this->walk($value, $this->pointer($path, $key), $depth + 1, $insideRepeat, false);
            }

            return;
        }

        $allowed = ['$faker', '$args', '$format', '$repeat', '$item', '$pick', '$weights', '$maybe', '$value', '$else', '$literal'];
        foreach ($reserved as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->issue('error', 'RESERVED_KEY', $this->pointer($path, $key), "Unknown reserved template key: {$key}.", $key);
            }
        }

        $directives = array_values(array_intersect($reserved, ['$faker', '$repeat', '$pick', '$maybe', '$literal']));
        if (count($directives) !== 1) {
            $this->issue('error', 'RESERVED_KEY', $path, 'A directive object must contain exactly one primary directive.');

            return;
        }

        $directive = $directives[0];
        $allowedKeys = match ($directive) {
            '$faker' => ['$faker', '$args', '$format'],
            '$repeat' => ['$repeat', '$item'],
            '$pick' => ['$pick', '$weights'],
            '$maybe' => ['$maybe', '$value', '$else'],
            '$literal' => ['$literal'],
        };
        foreach (array_keys($values) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $this->issue('error', str_starts_with($key, '$') ? 'RESERVED_KEY' : 'BAD_ARGS', $this->pointer($path, $key), "{$key} is not valid in a {$directive} directive.", $key);
            }
        }

        match ($directive) {
            '$faker' => $this->validateFakerDirective($values, $path),
            '$repeat' => $this->validateRepeatDirective($values, $path, $depth),
            '$pick' => $this->validatePickDirective($values, $path, $depth, $insideRepeat),
            '$maybe' => $this->validateMaybeDirective($values, $path, $depth, $insideRepeat),
            '$literal' => $this->walk($values['$literal'], $this->pointer($path, '$literal'), $depth + 1, $insideRepeat, true),
        };
    }

    /** @param array<string, mixed> $values */
    private function validateFakerDirective(array $values, string $path): void
    {
        $method = $values['$faker'] ?? null;
        if (! is_string($method) || $method === '') {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$faker'), '$faker must name a Faker method.');

            return;
        }

        $args = $this->normalizeJsonArray($values['$args'] ?? []);
        if (! is_array($args) || ! array_is_list($args)) {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$args'), '$args must be a JSON array.');

            return;
        }

        if (isset($values['$format']) && ! in_array($values['$format'], ['iso', 'date', 'epochMs', 'epochS'], true)) {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$format'), '$format must be iso, date, epochMs, or epochS.');
        }

        if (isset($values['$format'])) {
            $resolved = $this->catalog->resolve($method);
            if (in_array($resolved['status'], ['current', 'renamed'], true) && ! str_starts_with($resolved['id'], 'date.')) {
                $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$format'), '$format can only be used with a date method.');
            }
        }

        $this->validateMethod($method, $args, $this->pointer($path, '$faker'), '$'.$method);
    }

    /** @param array<string, mixed> $values */
    private function validateRepeatDirective(array $values, string $path, int $depth): void
    {
        if (! array_key_exists('$item', $values)) {
            $this->issue('error', 'BAD_ARGS', $path, '$repeat requires an $item template.');

            return;
        }

        $repeat = $values['$repeat'] ?? null;
        $maximum = null;
        if (is_int($repeat)) {
            $maximum = $repeat;
        } elseif ($repeat instanceof stdClass) {
            $range = get_object_vars($repeat);
            if (is_int($range['min'] ?? null) && is_int($range['max'] ?? null) && $range['min'] >= 0 && $range['min'] <= $range['max']) {
                $maximum = $range['max'];
            }
        }

        if ($maximum === null || $maximum < 0) {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$repeat'), '$repeat must be a non-negative integer or a valid min/max range.');
        } elseif ($maximum > (int) config('mock.templates.max_repeat', 1000)) {
            $this->issue('error', 'LIMIT_EXCEEDED', $this->pointer($path, '$repeat'), '$repeat cannot exceed 1,000 items.');
        }

        $this->walk($values['$item'], $this->pointer($path, '$item'), $depth + 1, true, false);
    }

    /** @param array<string, mixed> $values */
    private function validatePickDirective(array $values, string $path, int $depth, bool $insideRepeat): void
    {
        $pick = $values['$pick'] ?? null;
        if (! is_array($pick) || $pick === []) {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$pick'), '$pick must be a non-empty JSON array.');

            return;
        }

        $weights = $values['$weights'] ?? null;
        if ($weights !== null) {
            if (! is_array($weights) || count($weights) !== count($pick)) {
                $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$weights'), '$weights must contain one positive number for each $pick value.');
            } else {
                foreach ($weights as $weight) {
                    if ((! is_int($weight) && ! is_float($weight)) || $weight <= 0) {
                        $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$weights'), '$weights must contain only positive numbers.');

                        break;
                    }
                }
            }
        }

        foreach ($pick as $index => $item) {
            $this->walk($item, $this->pointer($this->pointer($path, '$pick'), (string) $index), $depth + 1, $insideRepeat, false);
        }
    }

    /** @param array<string, mixed> $values */
    private function validateMaybeDirective(array $values, string $path, int $depth, bool $insideRepeat): void
    {
        $probability = $values['$maybe'] ?? null;
        if ((! is_int($probability) && ! is_float($probability)) || $probability < 0 || $probability > 1) {
            $this->issue('error', 'BAD_ARGS', $this->pointer($path, '$maybe'), '$maybe must be a probability from 0 through 1.');
        }
        if (! array_key_exists('$value', $values)) {
            $this->issue('error', 'BAD_ARGS', $path, '$maybe requires a $value template.');

            return;
        }

        $this->walk($values['$value'], $this->pointer($path, '$value'), $depth + 1, $insideRepeat, false);

        if (array_key_exists('$else', $values)) {
            $this->walk($values['$else'], $this->pointer($path, '$else'), $depth + 1, $insideRepeat, false);
        }
    }

    private function validateString(string $value, string $path, bool $insideRepeat): void
    {
        if (str_starts_with($value, '$$')) {
            return;
        }

        if ($value === '$index') {
            if (! $insideRepeat) {
                $this->issue('error', 'UNKNOWN_METHOD', $path, '$index is only available inside $repeat.', '$index');
            }

            return;
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)(?:\((.*)\))?$/s', $value, $match) === 1) {
            $args = $this->parseInlineArguments($match[2] ?? '', $path, $value);
            if ($args !== null) {
                $this->validateMethod($match[1], $args, $path, $value);
            }

            return;
        }

        $protected = str_replace('\\{{', "\0ESCAPED_OPEN\0", $value);
        if (preg_match_all('/\{\{([^{}]+)\}\}/', $protected, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $expression = trim($match[1]);
                if ($expression === '$index') {
                    if (! $insideRepeat) {
                        $this->issue('error', 'UNKNOWN_METHOD', $path, '{{$index}} is only available inside $repeat.', '{{$index}}');
                    }
                    continue;
                }

                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)(?:\((.*)\))?$/s', $expression, $methodMatch) !== 1) {
                    $this->issue('error', 'UNKNOWN_METHOD', $path, "Unknown interpolation token: {{$expression}}.", '{{'.$expression.'}}');

                    continue;
                }

                $args = $this->parseInlineArguments($methodMatch[2] ?? '', $path, '{{'.$expression.'}}');
                if ($args !== null) {
                    $this->validateMethod($methodMatch[1], $args, $path, '{{'.$expression.'}}');
                }
            }
        }
    }

    /** @param list<mixed> $args */
    private function validateMethod(string $method, array $args, string $path, string $token): void
    {
        $resolved = $this->catalog->resolve($method);
        if ($resolved['status'] === 'blocked') {
            $this->issue('error', 'BLOCKED_METHOD', $path, "Faker method {$method} is blocked.", $token);

            return;
        }
        if ($resolved['status'] === 'unknown') {
            $suggestion = $resolved['suggestion'] === null ? null : str_replace($method, $resolved['suggestion'], $token);
            $message = "Unknown Faker method: {$method}.";
            if ($resolved['suggestion'] !== null) {
                $message .= " Did you mean {$resolved['suggestion']}?";
            }
            $this->issue('error', 'UNKNOWN_METHOD', $path, $message, $token, $suggestion);

            return;
        }
        if ($resolved['status'] === 'renamed') {
            $this->issue(
                'warning',
                'RENAMED_METHOD',
                $path,
                "{$method} was renamed to {$resolved['id']}.",
                $token,
                str_replace($method, $resolved['id'], $token),
            );
        }

        $error = $this->catalog->validateArguments($resolved['id'], $args);
        if ($error !== null) {
            $this->issue('error', str_contains($error, 'limit') ? 'LIMIT_EXCEEDED' : 'BAD_ARGS', $path, $error, $token);
        }
    }

    /** @return list<mixed>|null */
    private function parseInlineArguments(string $text, string $path, string $token): ?array
    {
        if ($text === '') {
            return [];
        }
        if (strlen($text) > (int) config('mock.templates.max_args_bytes', 4096)) {
            $this->issue('error', 'LIMIT_EXCEEDED', $path, 'Faker arguments exceed the configured 4 KB limit.', $token);

            return null;
        }

        try {
            $args = json_decode('['.$text.']', true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->issue('error', 'BAD_ARGS', $path, 'Faker arguments must be comma-separated JSON values: '.$exception->getMessage(), $token);

            return null;
        }

        return $args;
    }

    private function issue(
        string $severity,
        string $code,
        string $path,
        string $message,
        string $needle = '',
        ?string $suggestion = null,
    ): void {
        [$line, $col] = $this->location($needle);
        $this->issues[] = new TemplateIssue($severity, $code, $path, $line, $col, $message, $suggestion);
    }

    /** @return array{int, int} */
    private function location(string $needle): array
    {
        $offset = $needle === '' ? 0 : strpos($this->source, $needle);
        if ($offset === false) {
            return [1, 1];
        }

        $before = substr($this->source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastBreak = strrpos($before, "\n");
        $col = $offset - ($lastBreak === false ? -1 : $lastBreak);

        return [$line, $col];
    }

    private function pointer(string $path, string $segment): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $segment);

        return $path === '/' ? '/'.$escaped : $path.'/'.$escaped;
    }

    private function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return true;
            }
        }

        return false;
    }

    private function normalizeJsonArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = $this->normalizeJsonArray($item);
            }

            return $normalized;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeJsonArray($item), $value);
        }

        return $value;
    }
}
