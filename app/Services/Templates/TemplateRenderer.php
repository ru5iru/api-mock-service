<?php

namespace App\Services\Templates;

use DateTimeImmutable;
use DateTimeInterface;
use Faker\Generator;
use JsonException;
use stdClass;
use Stringable;
use Throwable;

final class TemplateRenderer
{
    private int $renderedNodes = 0;

    private Generator $faker;

    private string $locale = 'en';

    /** @var array<string, mixed>|null */
    private ?array $context = null;

    public function __construct(private readonly FakerMethodCatalog $catalog) {}

    public function render(CompiledTemplate $template, string $locale, string $seedMode, ?int $seed, ?string $requestHash = null, ?array $context = null): TemplateRenderResult
    {
        $startedAt = hrtime(true);
        $this->renderedNodes = 0;
        $this->locale = $locale;
        $this->context = $context;
        $this->faker = $this->catalog->faker($locale);

        if ($seedMode === 'fixed') {
            $this->faker->seed($seed ?? 0);
        } elseif ($seedMode === 'request') {
            $this->faker->seed($this->requestSeed($requestHash ?? hash('sha256', $template->source)));
        }

        try {
            $output = $this->renderNode($template->root, '/', null, false);
            $json = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (TemplateRenderException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new TemplateRenderException('Rendered output could not be encoded as JSON.', '/', '', 'TEMPLATE_RENDER_FAILED');
        } catch (Throwable $exception) {
            throw new TemplateRenderException($exception->getMessage(), '/', '', 'TEMPLATE_RENDER_FAILED');
        }

        $bytes = strlen($json);
        if ($bytes > (int) config('mock.templates.max_output_bytes', 1048576)) {
            throw new TemplateRenderException('Rendered output exceeds the configured 1 MiB limit.', '/', '', 'LIMIT_EXCEEDED');
        }

        return new TemplateRenderResult(
            $output,
            $json,
            $bytes,
            round((hrtime(true) - $startedAt) / 1_000_000, 2),
        );
    }

    private function renderNode(mixed $node, string $path, ?int $index, bool $literal): mixed
    {
        $this->renderedNodes++;
        if ($this->renderedNodes > (int) config('mock.templates.max_rendered_nodes', 100000)) {
            throw new TemplateRenderException('Rendered output exceeds the configured node limit.', $path, '', 'LIMIT_EXCEEDED');
        }

        if ($literal) {
            return $this->literalCopy($node);
        }

        if (is_string($node)) {
            return $this->renderString($node, $path, $index);
        }

        if (is_array($node)) {
            $rendered = [];
            foreach ($node as $key => $value) {
                $rendered[] = $this->renderNode($value, $this->pointer($path, (string) $key), $index, false);
            }

            return $rendered;
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $values = get_object_vars($node);
        foreach (['$faker', '$repeat', '$pick', '$maybe', '$literal'] as $directive) {
            if (array_key_exists($directive, $values)) {
                return $this->renderDirective($directive, $values, $path, $index);
            }
        }

        $rendered = new stdClass;
        foreach ($values as $key => $value) {
            $rendered->{$key} = $this->renderNode($value, $this->pointer($path, $key), $index, false);
        }

        return $rendered;
    }

    /** @param array<string, mixed> $values */
    private function renderDirective(string $directive, array $values, string $path, ?int $index): mixed
    {
        if ($directive === '$literal') {
            return $this->renderNode($values['$literal'], $this->pointer($path, '$literal'), $index, true);
        }

        if ($directive === '$faker') {
            $method = (string) $values['$faker'];
            $value = $this->invoke($method, $this->normalizeJsonArray($values['$args'] ?? []), $this->pointer($path, '$faker'), '$'.$method);

            return $this->normalizeValue($value, $values['$format'] ?? null, $path, '$'.$method);
        }

        if ($directive === '$repeat') {
            $repeat = $values['$repeat'];
            if ($repeat instanceof stdClass) {
                $range = get_object_vars($repeat);
                $count = $this->faker->numberBetween((int) $range['min'], (int) $range['max']);
            } else {
                $count = (int) $repeat;
            }
            if ($count > (int) config('mock.templates.max_repeat', 1000)) {
                throw new TemplateRenderException('$repeat cannot exceed 1,000 items.', $this->pointer($path, '$repeat'), '$repeat', 'LIMIT_EXCEEDED');
            }

            $result = [];
            for ($itemIndex = 0; $itemIndex < $count; $itemIndex++) {
                $result[] = $this->renderNode($values['$item'], $this->pointer($path, (string) $itemIndex), $itemIndex, false);
            }

            return $result;
        }

        if ($directive === '$pick') {
            $items = $values['$pick'];
            $selected = 0;
            if (isset($values['$weights'])) {
                $total = array_sum($values['$weights']);
                $cursor = $this->faker->randomFloat(12, 0, $total);
                foreach ($values['$weights'] as $position => $weight) {
                    $cursor -= $weight;
                    if ($cursor <= 0) {
                        $selected = $position;
                        break;
                    }
                }
            } else {
                $selected = $this->faker->numberBetween(0, count($items) - 1);
            }

            return $this->renderNode($items[$selected], $this->pointer($this->pointer($path, '$pick'), (string) $selected), $index, false);
        }

        $selected = $this->faker->randomFloat(12, 0, 1) <= (float) $values['$maybe']
            ? $values['$value']
            : ($values['$else'] ?? null);

        return $this->renderNode($selected, $this->pointer($path, '$maybe'), $index, false);
    }

    private function renderString(string $value, string $path, ?int $index): mixed
    {
        if (str_starts_with($value, '$$')) {
            return substr($value, 1);
        }

        if ($value === '$index') {
            if ($index === null) {
                throw new TemplateRenderException('$index is only available inside $repeat.', $path, '$index');
            }

            return $index;
        }

        if ($this->context !== null && preg_match('/^\$request\.[A-Za-z_][A-Za-z0-9_.-]*$/D', $value) === 1) {
            return $this->contextValue(substr($value, 1), $path);
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)(?:\((.*)\))?$/s', $value, $match) === 1) {
            $args = $this->inlineArguments($match[2] ?? '', $path, $value);

            return $this->normalizeValue($this->invoke($match[1], $args, $path, $value), null, $path, $value);
        }

        $sentinel = "\0MOCKDECK_ESCAPED_OPEN\0";
        $protected = str_replace('\\{{', $sentinel, $value);
        $rendered = preg_replace_callback('/\{\{([^{}]+)\}\}/', function (array $match) use ($path, $index): string {
            $expression = trim($match[1]);
            if ($expression === '$index') {
                if ($index === null) {
                    throw new TemplateRenderException('{{$index}} is only available inside $repeat.', $path, '{{$index}}');
                }

                return (string) $index;
            }

            if ($this->context !== null && preg_match('/^(?:env|\$?request)\.[A-Za-z_][A-Za-z0-9_.-]*$/D', $expression) === 1) {
                $resolved = $this->contextValue(ltrim($expression, '$'), $path);

                return is_scalar($resolved) || $resolved === null
                    ? (string) $resolved
                    : json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)(?:\((.*)\))?$/s', $expression, $methodMatch) !== 1) {
                throw new TemplateRenderException("Unknown interpolation token: {{$expression}}.", $path, '{{'.$expression.'}}');
            }
            $args = $this->inlineArguments($methodMatch[2] ?? '', $path, '{{'.$expression.'}}');
            $value = $this->normalizeValue($this->invoke($methodMatch[1], $args, $path, '{{'.$expression.'}}'), null, $path, '{{'.$expression.'}}');

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if ($value === null) {
                return '';
            }
            if (is_array($value) || $value instanceof stdClass) {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            return (string) $value;
        }, $protected);

        $rendered ??= $protected;
        if ($this->context !== null) {
            $rendered = preg_replace_callback('/(?<![\$A-Za-z0-9_])\$request\.[A-Za-z_][A-Za-z0-9_.-]*/', function (array $match) use ($path): string {
                $resolved = $this->contextValue(substr($match[0], 1), $path);

                return is_scalar($resolved) || $resolved === null
                    ? (string) $resolved
                    : json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }, $rendered);
        }

        return str_replace($sentinel, '{{', $rendered);
    }

    private function contextValue(string $expression, string $path): mixed
    {
        [$namespace, $key] = explode('.', $expression, 2);
        $values = $this->context[$namespace] ?? [];

        if ($namespace === 'env') {
            if (! array_key_exists($key, $values)) {
                throw new TemplateRenderException("Unknown environment variable: {$key}.", $path, '{{'.$expression.'}}');
            }

            return $values[$key];
        }

        foreach (explode('.', $key) as $part) {
            if ($namespace === 'request' && str_starts_with($key, 'headers.') && is_array($values)) {
                $matching = array_filter(array_keys($values), static fn (string $header): bool => strcasecmp($header, $part) === 0);
                if ($matching !== []) {
                    $part = (string) reset($matching);
                }
            }
            if (! is_array($values) || ! array_key_exists($part, $values)) {
                throw new TemplateRenderException("Unknown request field: {$key}.", $path, '$'.$expression);
            }
            $values = $values[$part];
        }

        return $values;
    }

    /** @return list<mixed> */
    private function inlineArguments(string $text, string $path, string $token): array
    {
        if ($text === '') {
            return [];
        }

        try {
            return json_decode('['.$text.']', true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TemplateRenderException('Faker arguments are not valid JSON.', $path, $token, 'BAD_ARGS');
        }
    }

    /** @param list<mixed> $args */
    private function invoke(string $method, array $args, string $path, string $token): mixed
    {
        try {
            return $this->catalog->invoke($method, $args, $this->locale);
        } catch (Throwable $exception) {
            throw new TemplateRenderException($exception->getMessage(), $path, $token);
        }
    }

    private function normalizeValue(mixed $value, mixed $format, string $path, string $token): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);

            return match ($format) {
                'date' => $date->format('Y-m-d'),
                'epochMs' => ((int) $date->format('U')) * 1000 + (int) $date->format('v'),
                'epochS' => $date->getTimestamp(),
                default => $date->format(DateTimeInterface::ATOM),
            };
        }

        if ($format !== null) {
            throw new TemplateRenderException('$format can only be used with a date result.', $path, $token, 'BAD_ARGS');
        }

        if (is_resource($value) || is_callable($value)) {
            throw new TemplateRenderException('Faker returned an unsupported value type.', $path, $token);
        }
        if (is_object($value) && ! $value instanceof stdClass && ! $value instanceof Stringable) {
            throw new TemplateRenderException('Faker returned an unsupported object.', $path, $token);
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $value;
    }

    private function literalCopy(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = $this->literalCopy($item);
            }

            return $copy;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->literalCopy($item), $value);
        }

        return $value;
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

    private function requestSeed(string $hash): int
    {
        $hex = ctype_xdigit($hash) ? substr($hash, 0, 16) : substr(hash('sha256', $hash), 0, 16);
        $upper = hexdec(substr($hex, 0, 8));
        $lower = hexdec(substr($hex, 8, 8));

        return (int) (($upper ^ $lower) % 2147483647);
    }

    private function pointer(string $path, string $segment): string
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $segment);

        return $path === '/' ? '/'.$escaped : $path.'/'.$escaped;
    }
}
