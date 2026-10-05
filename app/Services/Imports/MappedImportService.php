<?php

namespace App\Services\Imports;

use App\Models\Collection;
use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Config\ImportPlan;
use App\Services\Config\ImportSummary;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Shared mapped-document preview/persistence boundary for external format adapters. */
final readonly class MappedImportService
{
    public function __construct(private CurlParser $parser, private CurlHasher $hasher, private RevisionManager $revisions) {}

    public function preview(ImportMapper $mapper, string $json, ImportMode $mode, bool $maskSecrets = false): ImportPlan
    {
        $token = (string) Str::uuid();
        $digest = hash('sha256', $json.($maskSecrets ? ':masked' : ':original'));
        try {
            $document = $mapper->map($json, $maskSecrets);
        } catch (InvalidArgumentException $exception) {
            return new ImportPlan($token, $digest, $mode, true, [], [], [$exception->getMessage()], []);
        }
        // Cache only mapped configuration. Postman script text never reaches persistence.
        Cache::put('mapped-import:'.$token, compact('document', 'digest', 'mode'), now()->addMinutes(15));

        return $this->analyze($document, $mode, $token, $digest);
    }

    public function apply(string $token, string $digest): ImportSummary
    {
        return Cache::lock('mapped-import-apply', 120)->block(5, function () use ($token, $digest): ImportSummary {
            $cached = Cache::get('mapped-import:'.$token);
            if (! is_array($cached) || ! hash_equals($cached['digest'], $digest)) {
                throw new InvalidArgumentException('The import preview expired or changed. Preview the file again.');
            }
            $summary = DB::transaction(function () use ($cached, $token, $digest): ImportSummary {
                $document = $cached['document'];
                $mode = $cached['mode'];
                $plan = $this->analyze($document, $mode, $token, $digest);
                $created = $updated = $responseCreated = $responseUpdated = $responseDeleted = $snapshots = 0;
                $batch = (string) Str::uuid();
                foreach ($document['folders'] as $folder) {
                    Collection::query()->firstOrCreate(['name' => $folder]);
                }
                foreach ($document['items'] as $index => $item) {
                    $action = $plan->items[$index];
                    if ($action['action'] === 'skip') {
                        continue;
                    }
                    $endpoint = $action['action'] === 'update'
                        ? MockEndpoint::query()->where('uuid', $action['uuid'])->lockForUpdate()->firstOrFail()
                        : new MockEndpoint(['enabled' => true, 'priority' => 0]);
                    $oldResponses = $endpoint->exists ? $endpoint->responses()->get() : collect();
                    $before = $endpoint->exists ? [...$this->revisions->snapshot($endpoint), 'import_response_pool' => $oldResponses->map(fn ($response) => $this->revisions->snapshot($response))->all()] : null;
                    $parsed = $this->parser->parse($item['raw_curl']);
                    $variant = $this->hasher->forOptions($parsed, false, false, false, $item['excluded_query_params'], $item['excluded_headers'], $item['path_pattern_enabled']);
                    $collection = Collection::query()->firstOrCreate(['name' => $item['collection']]);
                    $endpoint->fill([
                        'name' => $item['name'], 'collection_id' => $collection->id,
                        'method' => $parsed->method, 'raw_curl' => $item['raw_curl'],
                        'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
                        'signature_version' => (int) substr($variant->name, 1),
                        'exclude_cookies' => false, 'exclude_auth' => false, 'exclude_headers' => false,
                        'excluded_query_params' => $item['excluded_query_params'], 'excluded_headers' => $item['excluded_headers'],
                        'path_pattern_enabled' => $item['path_pattern_enabled'],
                        'selection_mode' => 'weighted', 'sequence_on_exhaust' => null,
                        'external_source' => ['type' => $document['type'], 'correlation_key' => $item['correlation_key'], 'spec_title' => $document['title'], 'imported_at' => now()->utc()->toIso8601String()],
                    ])->save();
                    $before === null ? $created++ : $updated++;
                    foreach ($item['responses'] as $position => $response) {
                        // Imported examples are static, equally weighted, with no inferred callback/fault intent.
                        $attributes = [...$response, 'delay_ms' => 0, 'sequence_order' => null, 'is_default' => false,
                            'template' => null, 'editor_view' => 'json', 'seed_mode' => 'random', 'seed' => null, 'locale' => 'en',
                            'fault_enabled' => false, 'fault_type' => 'delay', 'fault_delay_ms_min' => 0, 'fault_delay_ms_max' => null, 'fault_probability' => 100,
                            'callback_enabled' => false, 'callback_url' => null, 'callback_headers' => null, 'callback_body' => null,
                            'callback_signing_enabled' => false, 'callback_signing_secret' => null];
                        if (isset($oldResponses[$position])) {
                            $oldResponses[$position]->fill($attributes)->save();
                            $oldResponses[$position]->rules()->delete();
                            $responseUpdated++;
                        } else {
                            $endpoint->responses()->create($attributes);
                            $responseCreated++;
                        }
                    }
                    foreach ($oldResponses->slice(count($item['responses'])) as $response) {
                        $response->delete();
                        $responseDeleted++;
                    }
                    if ($before !== null) {
                        $this->revisions->record($endpoint, $before, 'import', $batch, 'External import: '.$document['type']);
                        $snapshots++;
                    }
                }

                return new ImportSummary($created, $updated, $responseCreated, $responseUpdated, $responseDeleted, 0, $plan->metadata['warning_count'], $snapshots, $snapshots > 0 ? $batch : null);
            });
            Cache::forget('mapped-import:'.$token);

            return $summary;
        });
    }

    private function analyze(array $document, ImportMode $mode, string $token, string $digest): ImportPlan
    {
        $counts = ['creates' => 0, 'updates' => 0, 'conflicts' => 0, 'revision_snapshots' => 0];
        $items = [];
        $seen = [];
        $warningCount = count($document['warnings']);
        $secrets = false;
        foreach ($document['items'] as $item) {
            $matches = MockEndpoint::query()->where('external_source->type', $document['type'])
                ->where('external_source->correlation_key', $item['correlation_key'])->orderBy('id')->get();
            $existing = $matches->first();
            $duplicate = isset($seen[$item['correlation_key']]);
            $action = $mode === ImportMode::Clone ? 'create' : ($duplicate ? 'skip' : ($existing ? ($mode === ImportMode::CreateOnly ? 'skip' : 'update') : 'create'));
            $warnings = $item['warnings'];
            if ($duplicate && $mode !== ImportMode::Clone) {
                $warnings[] = 'Duplicate correlation key in this file; only the first request is imported.';
            }
            if ($matches->count() > 1 && $mode === ImportMode::Upsert) {
                $warnings[] = 'Multiple imported copies share this key; Update matching targets the oldest endpoint.';
            }
            $items[] = [
                'uuid' => $existing?->uuid ?? '', 'name' => $item['name'], 'method' => $item['method'], 'path' => $item['path'],
                'variant' => $item['variant'], 'collection' => $item['collection'], 'response_count' => count($item['responses']),
                'action' => $action, 'warnings' => $warnings,
                'message' => match ($action) {
                    'update' => 'Replace the matched endpoint request and saved response pool.', 'skip' => 'Correlation key already present; no changes.', default => 'Create endpoint and saved examples.'
                },
            ];
            if ($action !== 'skip') {
                $counts[$action === 'create' ? 'creates' : 'updates']++;
            }
            if ($action === 'update') {
                $counts['revision_snapshots']++;
            }
            $seen[$item['correlation_key']] = true;
            $warningCount += count($warnings);
            $secrets = $secrets || $item['contains_secrets'];
        }

        return new ImportPlan($token, $digest, $mode, true, $counts, $items, [], $document['warnings'], [
            'format' => $document['type'], 'title' => $document['title'], 'request_count' => count($items),
            'folder_count' => count($document['folders']), 'warning_count' => $warningCount, 'contains_secrets' => $secrets,
        ]);
    }
}
