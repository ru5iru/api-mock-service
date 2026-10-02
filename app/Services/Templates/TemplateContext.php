<?php

namespace App\Services\Templates;

use App\Models\Environment;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Http\Request;

/** Shared request capture, sample context, and resolution for responses and callbacks. */
final readonly class TemplateContext
{
    public const MAX_BODY_BYTES = 65536;

    public function __construct(private EnvironmentContext $environments) {}

    /** @return array<string, mixed> */
    public function capture(Request $request, string $requestId): array
    {
        $body = $request->getContent();
        $omitted = strlen($body) > self::MAX_BODY_BYTES;
        $json = $omitted ? null : json_decode($body, true);

        return [
            'id' => $requestId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'body' => $omitted ? null : $body,
            'json' => $json,
            'headers' => array_map(static fn (array $values): string => (string) ($values[0] ?? ''), $request->headers->all()),
            'body_context_omitted' => $omitted,
            'body_bytes' => strlen($body),
            'context_limit_bytes' => self::MAX_BODY_BYTES,
        ];
    }

    /** @return array<string, mixed> */
    public function synthetic(string $id = 'preview', ?string $url = null): array
    {
        return [
            'id' => $id, 'method' => 'POST', 'url' => $url ?? url('/preview'),
            'body' => '{}', 'json' => [], 'headers' => [],
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function build(array $request, ?Environment $environment = null): array
    {
        return ['env' => $this->environments->variables($environment), 'request' => $request];
    }

    /** @return array<string, mixed> */
    public function preview(string $template): array
    {
        $context = ['env' => [], 'request' => $this->synthetic()];
        $references = $this->environmentKeys($template);
        if ($references !== []) {
            $environment = $this->environments->active();
            $secretKeys = $environment->variables()->where('is_secret', true)->pluck('key')->all();
            if (array_intersect($references, $secretKeys) !== []) {
                throw new TemplateRenderException('Preview hidden: the template references a secret environment variable.', '/', '', 'SECRET_PREVIEW_HIDDEN');
            }
            $context['env'] = $this->environments->variables($environment);
        }

        return $context;
    }

    public function usesContext(string $template): bool
    {
        foreach ($this->expressions($template) as $value) {
            if (preg_match('/(?<![\$A-Za-z0-9_])\$request\.|\{\{\s*(?:env|request)\./', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function environmentKeys(string $template): array
    {
        $keys = [];
        foreach ($this->expressions($template) as $value) {
            if (preg_match_all('/\{\{\s*env\.([A-Za-z_][A-Za-z0-9_.-]*)\s*\}\}/', $value, $matches)) {
                $keys = array_merge($keys, $matches[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> Active string syntax only; escaped strings and $literal are data. */
    private function expressions(string $template): array
    {
        $values = [];
        $walk = function (mixed $node) use (&$walk, &$values): void {
            if (is_string($node)) {
                if (! str_starts_with($node, '$$')) {
                    $values[] = preg_replace('/\\\\\{\{[^{}]*\}\}/', '', $node) ?? $node;
                }
            } elseif (is_array($node)) {
                foreach ($node as $item) {
                    $walk($item);
                }
            } elseif ($node instanceof \stdClass && ! property_exists($node, '$literal')) {
                foreach (get_object_vars($node) as $item) {
                    $walk($item);
                }
            }
        };
        $walk(json_decode($template));

        return $values;
    }

    public static function validRequestExpression(string $expression): bool
    {
        return preg_match('/^request\.(?:method|url|body|id|json|headers)(?:\.[A-Za-z0-9_-]+)*$/D', $expression) === 1
            && (preg_match('/^request\.(?:method|url|body|id)\./', $expression) !== 1);
    }

    /** @param array<string, mixed> $context */
    public function resolve(string $expression, array $context, string $path): mixed
    {
        [$namespace, $key] = explode('.', $expression, 2);
        $values = $context[$namespace] ?? [];
        if ($namespace === 'env') {
            if (! array_key_exists($key, $values)) {
                throw new TemplateRenderException("Unknown environment variable: {$key}.", $path, '{{'.$expression.'}}');
            }

            return $values[$key];
        }
        if (! self::validRequestExpression($expression)) {
            throw new TemplateRenderException("Unknown request field: {$key}.", $path, '$'.$expression);
        }
        foreach (explode('.', $key) as $position => $part) {
            if ($position > 0 && str_starts_with($key, 'headers.') && is_array($values)) {
                foreach (array_keys($values) as $header) {
                    if (strcasecmp((string) $header, $part) === 0) {
                        $part = $header;
                        break;
                    }
                }
            }
            if (! is_array($values) || ! array_key_exists($part, $values)) {
                return null;
            }
            $values = $values[$part];
        }

        return $values;
    }
}
