<?php

namespace App\Services\Config;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
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
    public function export(?array $endpointUuids = null, bool $redactSecrets = true): ConfigDocument
    {
        $endpoints = MockEndpoint::query()
            ->with('responses')
            ->when($endpointUuids !== null, fn ($query) => $query->whereIn('uuid', $endpointUuids))
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
                'responses' => $this->responses($endpoint->responses),
            ];
        })->all();

        $data = [
            '$schema' => 'https://mockdeck.dev/schemas/config-v1.1.json',
            'format' => 'mockdeck',
            'format_version' => '1.1',
            'exported_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'generator' => [
                'name' => 'MockDeck',
                'version' => config('mock.portable_config.generator_version', '1.1.0'),
            ],
            'options' => [
                'secrets_redacted' => $redactSecrets,
            ],
            'warnings' => $warnings,
            'endpoints' => $documentEndpoints,
        ];

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return new ConfigDocument($data, hash('sha256', $encoded));
    }

    /**
     * @param  Collection<int, MockResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function responses(Collection $responses): array
    {
        return $responses
            ->sortBy('uuid', SORT_STRING)
            ->values()
            ->map(function (MockResponse $response): array {
                $headers = array_map(static fn (mixed $value): string => (string) $value, $response->headers ?? []);
                uksort($headers, static fn (string $left, string $right): int => strcasecmp($left, $right));

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
                ];
            })
            ->all();
    }
}
