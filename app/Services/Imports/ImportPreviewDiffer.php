<?php

namespace App\Services\Imports;

use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\SecretRedactor;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Mcp\McpAuditRedactor;
use App\Services\Revisions\StructuralJsonDiffer;
use InvalidArgumentException;

/** Presentation-only, secret-safe prospective changes; never applies an import. */
final readonly class ImportPreviewDiffer
{
    public function __construct(private StructuralJsonDiffer $differ, private McpAuditRedactor $redactor) {}

    public function diff(array $before, array $after): array
    {
        return array_map(function (array $change): array {
            // Retain the field path when redacting scalar values (e.g. Authorization).
            foreach (['before', 'after'] as $side) {
                $segments = explode('.', $change['path']);
                $leaf = end($segments);
                $raw = $change[$side];
                if (in_array($leaf, ['curl', 'raw_curl'], true) && is_string($raw)) {
                    $raw = $this->safeCurl($raw);
                }
                $value = $this->redactor->redact([$leaf => $raw])[$leaf];
                $change[$side] = $this->redactor->redact([$change['path'] => $value])[$change['path']];
            }

            return $change;
        }, $this->differ->diff($this->normalize($before), $this->normalize($after)));
    }

    private function safeCurl(string $curl): string
    {
        try {
            $request = app(CurlParser::class)->parse($curl);
            $request = new ParsedCurl($request->method, $request->url, $request->headers, $this->redactor->redact($request->body));

            return app(SecretRedactor::class)->redact($request)->curl;
        } catch (InvalidArgumentException) {
            return '[Request redacted: curl could not be safely parsed]';
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map($this->normalize(...), $value) : $value;
    }

    public function native(MockEndpoint $endpoint, array $source, bool $replaceResponses): array
    {
        $before = app(ConfigExporter::class)->export([$endpoint->uuid], redactSecrets: false)->endpoints()[0];
        $after = $source;
        $after['enabled'] = (bool) $source['enabled'] && ! ($source['requires_secret_replacement'] ?? false);
        // Compare pools by stable identity, showing additions/removals without order noise.
        $before['responses'] = collect($before['responses'])->keyBy('uuid')->all();
        $after['responses'] = collect($after['responses'])->mapWithKeys(function (array $response) use ($before): array {
            $existing = $before['responses'][$response['uuid']] ?? [];
            if (! array_key_exists('callback', $response) && isset($existing['callback'])) {
                $response['callback'] = $existing['callback'];
            }

            return [$response['uuid'] => $response];
        })->all();
        if (! $replaceResponses) {
            $after['responses'] += $before['responses'];
        }
        foreach (['before', 'after'] as $side) {
            // These are export/review flags rather than persisted fields.
            unset(${$side}['requires_secret_replacement']);
            ${$side}['environment_overrides'] = (array) (${$side}['environment_overrides'] ?? []);
        }

        return $this->diff($before, $after);
    }
}
