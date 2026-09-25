<?php

namespace App\Services\Config;

use App\Models\Collection as EndpointCollection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Tag;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\HashVariant;
use App\Services\Curl\ParsedCurl;
use App\Services\Environments\EnvironmentContext;
use App\Services\Matching\MatchPrecedence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ConfigImporter
{
    public function __construct(
        private ConfigValidator $validator,
        private CurlParser $parser,
        private CurlHasher $hasher,
        private EnvironmentContext $environments,
    ) {}

    public function preview(string $json, ImportMode $mode, bool $replaceResponses = false): ImportPlan
    {
        $validation = $this->validator->validate($json);
        $token = bin2hex(random_bytes(24));

        if (! $validation->valid()) {
            return new ImportPlan(
                $token,
                hash('sha256', $json),
                $mode,
                $replaceResponses,
                $this->emptyCounts(),
                [],
                $validation->errors,
                $validation->warnings,
            );
        }

        $plan = $this->analyze(
            $validation->document,
            $mode,
            $replaceResponses,
            $validation->warnings,
            $token,
        );

        Cache::put($this->cacheKey($token), [
            'json' => $json,
            'digest' => $plan->digest,
            'mode' => $mode->value,
            'replace_responses' => $replaceResponses,
        ], now()->addSeconds(config('mock.portable_config.preview_ttl_seconds', 900)));

        return $plan;
    }

    public function apply(string $token, string $digest, bool $acknowledgeWarnings = false): ImportSummary
    {
        $cached = Cache::get($this->cacheKey($token));
        if (! is_array($cached) || ! isset($cached['json'], $cached['digest'], $cached['mode'])) {
            throw new InvalidArgumentException('The import preview expired. Preview the file again.');
        }

        if (! hash_equals((string) $cached['digest'], $digest)
            || ! hash_equals((string) $cached['digest'], hash('sha256', (string) $cached['json']))) {
            throw new InvalidArgumentException('The import preview no longer matches the uploaded document.');
        }

        $validation = $this->validator->validate((string) $cached['json']);
        if (! $validation->valid()) {
            throw new InvalidArgumentException('The configuration is no longer valid. Preview it again.');
        }

        $mode = ImportMode::from((string) $cached['mode']);
        $replaceResponses = (bool) ($cached['replace_responses'] ?? false);

        $summary = DB::transaction(function () use ($validation, $mode, $replaceResponses, $acknowledgeWarnings, $token): ImportSummary {
            $plan = $this->analyze(
                $validation->document,
                $mode,
                $replaceResponses,
                $validation->warnings,
                $token,
                true,
            );

            if (! $plan->canApply()) {
                throw new InvalidArgumentException('Conflicts changed after preview. Preview the file again.');
            }

            if ($plan->warnings !== [] && ! $acknowledgeWarnings) {
                throw new InvalidArgumentException('Acknowledge the import warnings before applying this configuration.');
            }

            return $this->persist($validation->document, $mode, $replaceResponses, count($plan->warnings));
        }, 3);

        Cache::forget($this->cacheKey($token));

        return $summary;
    }

    /**
     * @param  list<string>  $validationWarnings
     */
    private function analyze(
        ConfigDocument $document,
        ImportMode $mode,
        bool $replaceResponses,
        array $validationWarnings,
        string $token,
        bool $lock = false,
    ): ImportPlan {
        $items = [];
        $errors = [];
        $warnings = $validationWarnings;
        $counts = $this->emptyCounts();
        $documentHashes = [];

        foreach ($document->endpoints() as $index => $source) {
            $uuid = (string) $source['uuid'];
            $name = trim((string) ($source['name'] ?? '')) ?: $uuid;
            $matching = $source['request']['matching'];

            try {
                $parsed = $this->parser->parse((string) $source['request']['curl']);
                $variant = $this->hasher->forOptions(
                    $parsed,
                    (bool) $matching['exclude_cookies'],
                    (bool) $matching['exclude_auth'],
                    (bool) $matching['exclude_headers'],
                );
                if (trim((string) ($source['name'] ?? '')) === '') {
                    $parts = parse_url($parsed->url) ?: [];
                    $target = (string) ($parts['path'] ?? '/');
                    $name = strtoupper($parsed->method).' '.$target;
                }
            } catch (Throwable $exception) {
                $errors[] = "Endpoint {$name} has an invalid curl: ".$exception->getMessage();
                $items[] = $this->item($index, $uuid, $name, 'error', 'The curl cannot be normalized.');

                continue;
            }

            if ($lock) {
                $this->lockSignature($variant->hash);
            }

            if (isset($documentHashes[$variant->hash])) {
                $errors[] = "Endpoint {$name} duplicates another imported request signature.";
                $items[] = $this->item($index, $uuid, $name, 'error', 'Duplicate request signature in this document.');

                continue;
            }
            $documentHashes[$variant->hash] = true;

            $uuidQuery = MockEndpoint::query()->where('uuid', $uuid);
            if ($lock) {
                $uuidQuery->lockForUpdate();
            }

            $byUuid = $uuidQuery->first();
            $action = 'create';
            $message = 'Creates a new endpoint.';

            if ($mode === ImportMode::CreateOnly && $byUuid !== null) {
                $action = 'error';
                $message = 'Its UUID already exists locally.';
                $errors[] = "Endpoint {$name} cannot be created because its UUID already exists.";
            } elseif ($mode === ImportMode::Upsert && $byUuid !== null) {
                $action = 'update';
                $message = 'Updates the local endpoint with the same UUID.';
            } elseif ($mode === ImportMode::Clone) {
                $message = 'Creates a copy with new endpoint and response UUIDs.';
            }

            $targetId = $mode === ImportMode::Upsert ? $byUuid?->id : null;
            $signatureQuery = MockEndpoint::query()
                ->where('curl_hash', $variant->hash)
                ->when($targetId !== null, fn ($query) => $query->where('id', '!=', $targetId));
            if ($lock) {
                $signatureQuery->lockForUpdate();
            }
            $bySignature = $signatureQuery->orderByDesc('priority')->orderBy('id')->first();

            if ($bySignature !== null && $bySignature->id !== $targetId) {
                $action = 'error';
                $message = 'Its request signature collides with an existing endpoint.';
                $errors[] = "Endpoint {$name} has the same origin-independent request signature as ".($bySignature->name ?: $bySignature->uuid).'.';
            }

            if ($action !== 'error') {
                $responseConflict = $this->responseConflict($source, $mode, $targetId, $lock);
                if ($responseConflict !== null) {
                    $action = 'error';
                    $message = $responseConflict;
                    $errors[] = "Endpoint {$name}: {$responseConflict}";
                }
            }

            if ($action !== 'error'
                && (bool) $source['enabled']
                && ! (bool) ($source['requires_secret_replacement'] ?? false)) {
                array_push(
                    $warnings,
                    ...$this->overlapWarnings($source, $parsed, $variant, $targetId, $lock),
                );
            }

            if (($source['requires_secret_replacement'] ?? false) === true) {
                $warnings[] = "Endpoint {$name} was exported with redacted secrets and remains disabled.";
            }

            $counts['responses'] += count($source['responses']);
            if ($action === 'create') {
                $counts['creates']++;
            } elseif ($action === 'update') {
                $counts['updates']++;
            } else {
                $counts['conflicts']++;
            }

            $items[] = $this->item($index, $uuid, $name, $action, $message, $variant->name);
        }

        $warnings = array_values(array_unique($warnings));
        $counts['endpoints'] = count($document->endpoints());
        $counts['warnings'] = count($warnings);

        return new ImportPlan(
            $token,
            $document->digest,
            $mode,
            $replaceResponses,
            $counts,
            $items,
            $errors,
            $warnings,
        );
    }

    /** @param array<string, mixed> $source */
    private function responseConflict(array $source, ImportMode $mode, ?int $targetId, bool $lock): ?string
    {
        if ($mode === ImportMode::Clone) {
            return null;
        }

        foreach ($source['responses'] as $response) {
            $query = MockResponse::query()->where('uuid', $response['uuid']);
            if ($lock) {
                $query->lockForUpdate();
            }
            $existing = $query->first();

            if ($existing !== null && ($mode === ImportMode::CreateOnly || $existing->mock_endpoint_id !== $targetId)) {
                return 'A response UUID already belongs to another local endpoint.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function overlapWarnings(
        array $source,
        ParsedCurl $parsed,
        HashVariant $importedVariant,
        ?int $targetId,
        bool $lock,
    ): array {
        $query = MockEndpoint::query()
            ->where('enabled', true)
            ->where('method', strtoupper($parsed->method))
            ->when($targetId !== null, fn ($query) => $query->where('id', '!=', $targetId));
        if ($lock) {
            $query->lockForUpdate();
        }

        $warnings = [];
        $importedVariants = $this->hasher->variants($parsed);

        foreach ($query->get() as $local) {
            try {
                $localParsed = $this->parser->parse($local->raw_curl);
                $localVariants = $this->hasher->variants($localParsed);
            } catch (Throwable) {
                continue;
            }

            $localVariantName = $this->hasher->variantName(
                $local->exclude_cookies,
                $local->exclude_auth,
                $local->exclude_headers,
            );
            $importedMatchesLocalRequest = hash_equals(
                $importedVariant->hash,
                $localVariants[$importedVariant->name]->hash,
            );
            $localMatchesImportedRequest = hash_equals(
                $local->curl_hash,
                $importedVariants[$localVariantName]->hash,
            );

            if (! $importedMatchesLocalRequest && ! $localMatchesImportedRequest) {
                continue;
            }

            $importedName = trim((string) ($source['name'] ?? '')) ?: (string) $source['uuid'];
            $localName = $local->name ?: $local->uuid;
            $winner = $this->predictedWinner(
                (int) $source['priority'],
                $importedVariant->name,
                $local->priority,
                $localVariantName,
                $importedName,
                $localName,
            );
            $warnings[] = "Endpoint {$importedName} overlaps {$localName}; predicted winner: {$winner}.";
        }

        return $warnings;
    }

    private function predictedWinner(
        int $importedPriority,
        string $importedVariant,
        int $localPriority,
        string $localVariant,
        string $importedName,
        string $localName,
    ): string {
        if ($importedPriority !== $localPriority) {
            return $importedPriority > $localPriority ? $importedName : $localName;
        }

        $importedSpecificity = MatchPrecedence::specificity($importedVariant);
        $localSpecificity = MatchPrecedence::specificity($localVariant);

        if ($importedSpecificity !== $localSpecificity) {
            return $importedSpecificity > $localSpecificity ? $importedName : $localName;
        }

        return $localName.' (existing endpoint wins the ID tie-break)';
    }

    private function persist(
        ConfigDocument $document,
        ImportMode $mode,
        bool $replaceResponses,
        int $warningCount,
    ): ImportSummary {
        $endpointCreates = 0;
        $endpointUpdates = 0;
        $responseCreates = 0;
        $responseUpdates = 0;
        $responseDeletes = 0;
        $disabled = 0;
        $organization = $this->persistOrganization($document);

        foreach ($document->endpoints() as $source) {
            $matching = $source['request']['matching'];
            $parsed = $this->parser->parse((string) $source['request']['curl']);
            $variant = $this->hasher->forOptions(
                $parsed,
                (bool) $matching['exclude_cookies'],
                (bool) $matching['exclude_auth'],
                (bool) $matching['exclude_headers'],
            );

            $endpoint = $mode === ImportMode::Upsert
                ? MockEndpoint::query()->where('uuid', $source['uuid'])->first()
                : null;

            if ($endpoint === null) {
                $endpoint = new MockEndpoint;
                $endpoint->uuid = $mode === ImportMode::Clone ? (string) Str::uuid7() : $source['uuid'];
                $endpointCreates++;
            } else {
                $endpointUpdates++;
            }

            $enabled = (bool) $source['enabled'] && ! (bool) ($source['requires_secret_replacement'] ?? false);
            if (! $enabled) {
                $disabled++;
            }

            $endpoint->fill([
                'collection_id' => isset($source['collection']) ? ($organization['collections'][Str::lower($source['collection'])] ?? null) : null,
                'name' => $source['name'],
                'enabled' => $enabled,
                'priority' => $source['priority'],
                'method' => $parsed->method,
                'raw_curl' => $source['request']['curl'],
                'normalized_curl' => $variant->normalized,
                'curl_hash' => $variant->hash,
                'signature_version' => 2,
                'exclude_cookies' => $matching['exclude_cookies'],
                'exclude_auth' => $matching['exclude_auth'],
                'exclude_headers' => $matching['exclude_headers'],
            ])->save();

            $endpoint->tags()->sync(collect($source['tags'] ?? [])->map(
                static fn (string $name): ?int => $organization['tags'][Str::lower($name)] ?? null,
            )->filter()->values()->all());
            $endpoint->environmentOverrides()->sync(collect($source['environment_overrides'] ?? [])->mapWithKeys(
                static fn (bool $enabled, string $name): array => isset($organization['environments'][Str::lower($name)])
                    ? [$organization['environments'][Str::lower($name)] => ['enabled' => $enabled]]
                    : [],
            )->all());

            $importedResponseUuids = [];
            foreach ($source['responses'] as $responseSource) {
                $response = $mode === ImportMode::Upsert
                    ? $endpoint->responses()->where('uuid', $responseSource['uuid'])->first()
                    : null;

                if ($response === null) {
                    $response = $endpoint->responses()->make();
                    $response->uuid = $mode === ImportMode::Clone ? (string) Str::uuid7() : $responseSource['uuid'];
                    $responseCreates++;
                } else {
                    $responseUpdates++;
                }

                $response->fill([
                    'status_code' => $responseSource['status'],
                    'headers' => array_map(static fn (mixed $value): string => (string) $value, $responseSource['headers']),
                    'body' => $responseSource['body'],
                    'body_mode' => $responseSource['body_mode'] ?? 'static',
                    'template' => $responseSource['template'] ?? null,
                    'editor_view' => $responseSource['editor_view'] ?? 'builder',
                    'seed_mode' => $responseSource['seed_mode'] ?? 'random',
                    'seed' => $responseSource['seed'] ?? null,
                    'locale' => $responseSource['locale'] ?? 'en',
                    'delay_ms' => $responseSource['delay_ms'],
                    'weight' => $responseSource['weight'],
                ])->save();
                $importedResponseUuids[] = $response->uuid;
            }

            if ($mode === ImportMode::Upsert && $replaceResponses) {
                $deleteQuery = MockResponse::query()->where('mock_endpoint_id', $endpoint->id);
                if ($importedResponseUuids !== []) {
                    $deleteQuery->whereNotIn('uuid', $importedResponseUuids);
                }
                $responseDeletes += $deleteQuery->delete();
            }
        }

        return new ImportSummary(
            $endpointCreates,
            $endpointUpdates,
            $responseCreates,
            $responseUpdates,
            $responseDeletes,
            $disabled,
            $warningCount,
        );
    }

    /**
     * @return array{collections: array<string, int>, tags: array<string, int>, environments: array<string, int>}
     */
    private function persistOrganization(ConfigDocument $document): array
    {
        $collections = [];
        foreach ($document->collections() as $source) {
            $collection = EndpointCollection::query()->firstOrCreate(
                ['name' => trim((string) $source['name'])],
                ['description' => $source['description'] ?? null],
            );
            $collections[Str::lower($collection->name)] = $collection->id;
        }

        $tags = [];
        foreach ($document->tags() as $name) {
            $normalized = Str::lower(trim($name));
            $tag = Tag::query()->where('normalized_name', $normalized)->first()
                ?? Tag::query()->create(['name' => trim($name)]);
            $tags[$normalized] = $tag->id;
        }

        $environments = Environment::query()->get()->mapWithKeys(
            static fn (Environment $environment): array => [Str::lower($environment->name) => $environment->id],
        )->all();
        $default = null;
        foreach ($document->environments() as $source) {
            $name = trim((string) $source['name']);
            $environment = Environment::query()->firstOrCreate(['name' => $name], ['is_default' => false]);
            foreach ($source['variables'] as $variableSource) {
                $variable = $environment->variables()->firstOrNew(['key' => $variableSource['key']]);
                $variable->is_secret = (bool) $variableSource['is_secret'];
                if ($variableSource['value'] !== null) {
                    $variable->value = $variableSource['value'];
                }
                $variable->save();
            }
            if (($source['is_default'] ?? false) === true) {
                $default = $environment;
            }
            $environments[Str::lower($name)] = $environment->id;
        }
        if ($default !== null) {
            $this->environments->makeDefault($default);
        }

        return compact('collections', 'tags', 'environments');
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'endpoints' => 0,
            'responses' => 0,
            'creates' => 0,
            'updates' => 0,
            'conflicts' => 0,
            'warnings' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function item(
        int $index,
        string $uuid,
        string $name,
        string $action,
        string $message,
        ?string $variant = null,
    ): array {
        return compact('index', 'uuid', 'name', 'action', 'message', 'variant');
    }

    private function cacheKey(string $token): string
    {
        return 'mockdeck:config-import:'.$token;
    }

    private function lockSignature(string $hash): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$hash]);
        }
    }
}
