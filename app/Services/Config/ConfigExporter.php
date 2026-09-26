<?php

namespace App\Services\Config;

use App\Models\Collection as EndpointCollection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Tag;
use App\Services\Curl\CurlParser;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class ConfigExporter
{
    public function __construct(
        private CurlParser $parser,
        private SecretRedactor $redactor,
    ) {}

    /** @param list<string>|null $endpointUuids */
    public function export(
        ?array $endpointUuids = null,
        bool $redactSecrets = true,
        ?int $collectionId = null,
        ?int $environmentId = null,
    ): ConfigDocument {
        if ($collectionId !== null && $environmentId !== null) {
            throw new InvalidArgumentException('Choose either a collection scope or an environment scope, not both.');
        }

        $endpoints = MockEndpoint::query()
            ->with(['responses', 'collection', 'tags', 'environmentOverrides'])
            ->when($endpointUuids !== null, fn ($query) => $query->whereIn('uuid', $endpointUuids))
            ->when($collectionId !== null, fn ($query) => $query->where('collection_id', $collectionId))
            ->when($environmentId !== null, fn ($query) => $query->where(function ($query) use ($environmentId): void {
                $query->whereDoesntHave('environmentOverrides', fn ($override) => $override->whereKey($environmentId))
                    ->orWhereHas('environmentOverrides', fn ($override) => $override
                        ->whereKey($environmentId)
                        ->where('endpoint_environment_overrides.enabled', true));
            }))
            ->orderBy('uuid')
            ->get();

        $warnings = [];
        $documentEndpoints = $endpoints->map(function (MockEndpoint $endpoint) use ($redactSecrets, &$warnings): array {
            $curl = $endpoint->raw_curl;
            $requiresSecretReplacement = false;

            if ($redactSecrets) {
                try {
                    $redacted = $this->redactor->redact($this->parser->parse($curl));
                } catch (InvalidArgumentException) {
                    $label = $endpoint->name ?: $endpoint->uuid;

                    throw new InvalidArgumentException("Endpoint {$label} has an invalid curl and cannot be safely redacted.");
                }
                $curl = $redacted->curl;
                $requiresSecretReplacement = $redacted->changed;

                if ($requiresSecretReplacement) {
                    $warnings[] = [
                        'code' => 'request_secrets_redacted',
                        'endpoint_uuid' => $endpoint->uuid,
                        'message' => 'Sensitive request values were removed; review the curl before enabling this endpoint.',
                    ];
                }
            }

            return [
                'uuid' => $endpoint->uuid,
                'name' => $endpoint->name,
                'enabled' => $requiresSecretReplacement ? false : $endpoint->enabled,
                'priority' => $endpoint->priority,
                'collection' => $endpoint->collection?->name,
                'tags' => $endpoint->tags->pluck('name')->values()->all(),
                'environment_overrides' => (object) $endpoint->environmentOverrides
                    ->mapWithKeys(static fn ($environment): array => [$environment->name => (bool) $environment->pivot->enabled])
                    ->all(),
                'requires_secret_replacement' => $requiresSecretReplacement,
                'request' => [
                    'curl' => $curl,
                    'signature_version' => $endpoint->signature_version,
                    'matching' => [
                        'exclude_cookies' => $endpoint->exclude_cookies,
                        'exclude_auth' => $endpoint->exclude_auth,
                        'exclude_headers' => $endpoint->exclude_headers,
                    ],
                ],
                'responses' => $this->responses($endpoint->responses, $redactSecrets),
            ];
        })->all();

        $data = [
            '$schema' => 'https://mockdeck.dev/schemas/config-v1.2.json',
            'format' => 'mockdeck',
            'format_version' => '1.2',
            'exported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'generator' => [
                'name' => 'MockDeck',
                'version' => config('mock.portable_config.generator_version', '1.2.0'),
            ],
            'options' => [
                'secrets_redacted' => $redactSecrets,
                'environment_secrets_redacted' => true,
                'scope' => $collectionId !== null
                    ? ['type' => 'collection', 'id' => $collectionId]
                    : ($environmentId !== null ? ['type' => 'environment', 'id' => $environmentId] : ['type' => 'all']),
            ],
            'warnings' => $warnings,
            'collections' => EndpointCollection::query()->orderBy('name')->get(['name', 'description'])->toArray(),
            'tags' => Tag::query()->orderBy('name')->pluck('name')->all(),
            'environments' => Environment::query()->with('variables')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(static fn (Environment $environment): array => [
                    'name' => $environment->name,
                    'is_default' => $environment->is_default,
                    'variables' => $environment->variables->map(static fn ($variable): array => [
                        'key' => $variable->key,
                        'value' => $variable->is_secret ? null : $variable->value,
                        'is_secret' => $variable->is_secret,
                        'redacted' => $variable->is_secret,
                    ])->values()->all(),
                ])->values()->all(),
            'endpoints' => $documentEndpoints,
        ];

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return new ConfigDocument($data, hash('sha256', $encoded));
    }

    /**
     * @param  Collection<int, MockResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function responses(Collection $responses, bool $redactSecrets): array
    {
        return $responses
            ->sortBy('uuid', SORT_STRING)
            ->values()
            ->map(function (MockResponse $response) use ($redactSecrets): array {
                $headers = array_map(static fn (mixed $value): string => (string) $value, $response->headers ?? []);
                uksort($headers, static fn (string $left, string $right): int => strcasecmp($left, $right));
                $callbackHeaders = $response->callback_headers ?? [];
                $callbackUrl = $response->callback_url;
                $callbackRedacted = false;
                if ($redactSecrets) {
                    $sensitiveHeaders = array_map('strtolower', config('mock.portable_config.sensitive_headers', []));
                    foreach ($callbackHeaders as $name => &$value) {
                        if (in_array(strtolower($name), $sensitiveHeaders, true)) {
                            $value = '[redacted]';
                            $callbackRedacted = true;
                        }
                    }
                    unset($value);
                    $sensitiveKeys = array_map('strtolower', config('mock.portable_config.sensitive_query_keys', []));
                    if ($callbackUrl !== null) {
                        $callbackUrl = preg_replace_callback('/([?&])([^=&#]+)=([^&#]*)/', static function (array $match) use ($sensitiveKeys, &$callbackRedacted): string {
                            if (in_array(strtolower(rawurldecode($match[2])), $sensitiveKeys, true)) {
                                $callbackRedacted = true;

                                return $match[1].$match[2].'=[redacted]';
                            }

                            return $match[0];
                        }, $callbackUrl);
                    }
                }
                $callbackRedacted = $callbackRedacted
                    || ($response->callback_signing_enabled && $response->callback_signing_secret !== null);

                return [
                    'uuid' => $response->uuid,
                    'status' => $response->status_code,
                    'headers' => (object) $headers,
                    'body' => (string) ($response->body ?? ''),
                    'body_mode' => (string) ($response->body_mode ?? 'static'),
                    'template' => $response->template,
                    'editor_view' => (string) ($response->editor_view ?? 'builder'),
                    'seed_mode' => (string) ($response->seed_mode ?? 'random'),
                    'seed' => $response->seed,
                    'locale' => (string) ($response->locale ?? 'en'),
                    'delay_ms' => $response->delay_ms,
                    'weight' => $response->weight,
                    'callback' => [
                        'enabled' => (bool) $response->callback_enabled && ! $callbackRedacted,
                        'requires_secret_replacement' => $callbackRedacted,
                        'url' => $callbackUrl,
                        'method' => $response->callback_method,
                        'headers' => (object) $callbackHeaders,
                        'body' => $response->callback_body,
                        'delay_ms' => $response->callback_delay_ms,
                        'delay_max_ms' => $response->callback_delay_max_ms,
                        'retry' => $response->callback_retry,
                        'backoff_ms' => $response->callback_backoff_ms,
                        'timeout_ms' => $response->callback_timeout_ms,
                        'signing_enabled' => (bool) $response->callback_signing_enabled,
                        'signing_secret' => null,
                        'signing_secret_redacted' => $response->callback_signing_secret !== null,
                        'signature_header' => $response->callback_signature_header,
                    ],
                ];
            })
            ->all();
    }
}
