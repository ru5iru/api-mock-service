<?php

namespace App\Services\Revisions;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Models\Tag;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Response\SelectionConfigurationValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RevisionManager
{
    public function __construct(private StructuralJsonDiffer $differ) {}

    /** @return array<string, mixed> */
    public function snapshot(Model $entity): array
    {
        if ($entity instanceof MockEndpoint) {
            $entity->loadMissing(['tags', 'environmentOverrides']);

            return $this->normalize([
                'id' => $entity->id,
                'uuid' => $entity->uuid,
                'collection_id' => $entity->collection_id,
                'external_source' => $entity->external_source,
                'name' => $entity->name,
                'enabled' => (bool) $entity->enabled,
                'priority' => (int) $entity->priority,
                'selection_mode' => $entity->selection_mode ?? 'weighted',
                'sequence_on_exhaust' => $entity->selection_mode === 'sequence' ? ($entity->sequence_on_exhaust ?? 'repeat_last') : null,
                'method' => $entity->method,
                'raw_curl' => $entity->raw_curl,
                'normalized_curl' => $entity->normalized_curl,
                'curl_hash' => $entity->curl_hash,
                'signature_version' => (int) $entity->signature_version,
                'exclude_cookies' => (bool) $entity->exclude_cookies,
                'exclude_auth' => (bool) $entity->exclude_auth,
                'exclude_headers' => (bool) $entity->exclude_headers,
                'excluded_query_params' => $entity->excluded_query_params ?? [],
                'excluded_headers' => $entity->excluded_headers ?? [],
                'path_pattern_enabled' => (bool) $entity->path_pattern_enabled,
                'tags' => $entity->tags->modelKeys(),
                'environment_overrides' => $entity->environmentOverrides
                    ->mapWithKeys(static fn ($environment): array => [
                        (string) $environment->id => (bool) $environment->pivot->enabled,
                    ])->all(),
            ]);
        }

        if ($entity instanceof MockResponse) {
            $entity->loadMissing('rules');

            return $this->normalize([
                'id' => $entity->id,
                'mock_endpoint_id' => $entity->mock_endpoint_id,
                'uuid' => $entity->uuid,
                'external_label' => $entity->external_label,
                'status_code' => (int) $entity->status_code,
                'headers' => $entity->headers,
                'body' => $entity->body,
                'body_mode' => $entity->body_mode,
                'template' => $entity->template,
                'editor_view' => $entity->editor_view,
                'seed_mode' => $entity->seed_mode,
                'seed' => $entity->seed,
                'locale' => $entity->locale,
                'delay_ms' => (int) $entity->delay_ms,
                ...app(FaultConfigurationValidator::class)->attributes($entity->getAttributes()),
                'weight' => (int) $entity->weight,
                'sequence_order' => $entity->sequence_order,
                'is_default' => (bool) $entity->is_default,
                'response_rules' => $entity->rules->map(static fn ($rule): array => $rule->only(['field_type', 'field_name', 'operator', 'value', 'priority']))->values()->all(),
                'callback_enabled' => (bool) $entity->callback_enabled,
                'callback_url' => $entity->callback_url,
                'callback_method' => $entity->callback_method ?? 'POST',
                'callback_headers' => $entity->callback_headers,
                'callback_body' => $entity->callback_body,
                'callback_delay_ms' => (int) $entity->callback_delay_ms,
                'callback_delay_max_ms' => $entity->callback_delay_max_ms,
                'callback_retry' => max(1, (int) ($entity->callback_retry ?: 1)),
                'callback_backoff_ms' => (int) ($entity->callback_backoff_ms ?? 1000),
                'callback_timeout_ms' => max(1, (int) ($entity->callback_timeout_ms ?: 5000)),
                'callback_signing_enabled' => (bool) $entity->callback_signing_enabled,
                'callback_signing_secret' => $entity->getRawOriginal('callback_signing_secret'),
                'callback_signature_header' => $entity->callback_signature_header ?? 'X-MockDeck-Signature',
            ]);
        }

        throw new InvalidArgumentException('Only endpoints and responses can be versioned.');
    }

    /**
     * Records the supplied pre-state only when the entity now differs from it.
     *
     * @param  array<string, mixed>  $before
     */
    public function recordIfChanged(
        Model $entity,
        array $before,
        string $source = 'manual_edit',
        ?string $importBatchId = null,
        ?string $note = null,
    ): ?Revision {
        $entity->refresh();
        if ($entity instanceof MockEndpoint) {
            $entity->load(['tags', 'environmentOverrides']);
        }

        if ($this->snapshot($entity) === $this->normalize($before)) {
            return null;
        }

        return $this->record($entity, $before, $source, $importBatchId, $note);
    }

    /** @param array<string, mixed> $snapshot */
    public function record(
        Model $entity,
        array $snapshot,
        string $source = 'manual_edit',
        ?string $importBatchId = null,
        ?string $note = null,
    ): Revision {
        if (! in_array($source, ['manual_edit', 'import', 'rollback'], true)) {
            throw new InvalidArgumentException('Unsupported revision source.');
        }

        $type = $this->entityType($entity);
        $version = (int) Revision::query()
            ->where('entity_type', $type)
            ->where('entity_id', $entity->getKey())
            ->max('version_number') + 1;

        return Revision::query()->create([
            'entity_type' => $type,
            'entity_id' => $entity->getKey(),
            'version_number' => $version,
            'snapshot' => $this->normalize($snapshot),
            'source' => $source,
            'import_batch_id' => $importBatchId,
            'note' => $note,
        ]);
    }

    /** @return array<string, mixed> */
    public function diff(Revision $before, Revision $after): array
    {
        $this->assertSameEntity($before, $after);

        return $this->diffPayload(
            $before,
            $after->snapshot,
            ['kind' => 'revision', 'id' => $after->id, 'version' => $after->version_number],
        );
    }

    /** @return array<string, mixed> */
    public function diffCurrent(Revision $revision): array
    {
        $entity = $this->findEntity($revision->entity_type, $revision->entity_id);
        $current = $this->snapshot($entity);
        if ($entity instanceof MockEndpoint && array_key_exists('import_response_pool', $revision->snapshot)) {
            $current['import_response_pool'] = $entity->responses()->get()->map(fn ($response) => $this->snapshot($response))->all();
            $current = $this->normalize($current);
        }

        return $this->diffPayload(
            $revision,
            $current,
            ['kind' => 'current', 'id' => $entity->getKey(), 'version' => null],
        );
    }

    public function restore(Revision $revision): Revision
    {
        return DB::transaction(function () use ($revision): Revision {
            $entity = $this->applySnapshot($revision->entity_type, $revision->snapshot);
            $this->validateSelection($entity instanceof MockEndpoint ? $entity : $entity->endpoint);

            return $this->record(
                $entity,
                $revision->snapshot,
                'rollback',
                note: 'Restored version '.$revision->version_number,
            );
        }, 3);
    }

    /** @return array{batch_id: string, restored: int, revisions: list<int>} */
    public function undoImport(string $batchId): array
    {
        if (! Str::isUuid($batchId)) {
            throw new InvalidArgumentException('The import batch ID is invalid.');
        }

        return DB::transaction(function () use ($batchId): array {
            $revisions = Revision::query()
                ->where('import_batch_id', $batchId)
                ->where('source', 'import')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($revisions->isEmpty()) {
                throw new InvalidArgumentException('No reversible import was found for this batch.');
            }

            $created = [];
            foreach ($revisions as $revision) {
                $entity = $this->applySnapshot($revision->entity_type, $revision->snapshot);
                $created[] = $this->record(
                    $entity,
                    $revision->snapshot,
                    'rollback',
                    note: 'Undid import '.$batchId,
                )->id;
            }

            foreach ($revisions as $revision) {
                $entity = $this->findEntity($revision->entity_type, $revision->entity_id);
                $this->validateSelection($entity instanceof MockEndpoint ? $entity : $entity->endpoint);
            }

            return ['batch_id' => $batchId, 'restored' => count($created), 'revisions' => $created];
        }, 3);
    }

    public function summary(Revision $revision): string
    {
        if ($revision->note !== null && $revision->note !== '') {
            return $revision->note;
        }

        $next = Revision::query()
            ->where('entity_type', $revision->entity_type)
            ->where('entity_id', $revision->entity_id)
            ->where('version_number', '>', $revision->version_number)
            ->orderBy('version_number')
            ->first();
        $after = $next?->snapshot ?? $this->snapshot($this->findEntity($revision->entity_type, $revision->entity_id));
        $changes = $this->differ->diff($revision->snapshot, $after);

        if ($changes === []) {
            return 'No fields changed';
        }

        return collect($changes)->take(2)->map(function (array $change): string {
            $field = str_replace('_', ' ', $change['path']);

            return match ($change['path']) {
                'name' => 'renamed '.$this->short($change['before']).' → '.$this->short($change['after']),
                'body', 'template' => 'response '.$change['path'].' changed',
                default => $change['type'] === 'changed'
                    ? $field.' '.$this->short($change['before']).'→'.$this->short($change['after'])
                    : $field.' '.$change['type'],
            };
        })->implode(', ');
    }

    private function entityType(Model $entity): string
    {
        return match (true) {
            $entity instanceof MockEndpoint => 'endpoint',
            $entity instanceof MockResponse => 'response',
            default => throw new InvalidArgumentException('Only endpoints and responses can be versioned.'),
        };
    }

    private function findEntity(string $type, int $id): Model
    {
        return match ($type) {
            'endpoint' => MockEndpoint::query()->findOrFail($id),
            'response' => MockResponse::query()->findOrFail($id),
            default => throw new InvalidArgumentException('Unsupported revision entity type.'),
        };
    }

    /** @param array<string, mixed> $snapshot */
    private function applySnapshot(string $type, array $snapshot, bool $adjustSequence = true): Model
    {
        if ($type === 'endpoint') {
            $endpoint = MockEndpoint::query()->whereKey((int) $snapshot['id'])->lockForUpdate()->firstOrFail();
            $attributes = collect($snapshot)->except(['id', 'uuid', 'tags', 'environment_overrides', 'endpoint_call_state', 'call_states', 'import_response_pool'])->all();
            $attributes['external_source'] = $attributes['external_source'] ?? null;
            $attributes['selection_mode'] = $attributes['selection_mode'] ?? 'weighted';
            $attributes['sequence_on_exhaust'] = $attributes['selection_mode'] === 'sequence' ? ($attributes['sequence_on_exhaust'] ?? 'repeat_last') : null;
            $attributes['excluded_query_params'] = $attributes['excluded_query_params'] ?? [];
            $attributes['excluded_headers'] = $attributes['excluded_headers'] ?? [];
            $attributes['path_pattern_enabled'] = $attributes['path_pattern_enabled'] ?? false;
            $collectionId = $attributes['collection_id'] ?? null;
            $attributes['collection_id'] = $collectionId !== null && Collection::query()->whereKey($collectionId)->exists()
                ? $collectionId
                : null;
            $endpoint->fill($attributes)->save();
            $endpoint->tags()->sync(Tag::query()->whereKey($snapshot['tags'] ?? [])->pluck('id')->all());
            $validEnvironmentIds = Environment::query()
                ->whereKey(array_map('intval', array_keys($snapshot['environment_overrides'] ?? [])))
                ->pluck('id')
                ->all();
            $endpoint->environmentOverrides()->sync(collect($snapshot['environment_overrides'] ?? [])
                ->only($validEnvironmentIds)
                ->mapWithKeys(static fn (bool $enabled, int|string $id): array => [(int) $id => ['enabled' => $enabled]])
                ->all());

            // Optional configuration pool recorded by mapped imports. Runtime call state is untouched.
            if (array_key_exists('import_response_pool', $snapshot)) {
                $ids = array_column($snapshot['import_response_pool'], 'id');
                $endpoint->responses()->whereNotIn('id', $ids)->delete();
                foreach ($snapshot['import_response_pool'] as $responseSnapshot) {
                    $this->applySnapshot('response', $responseSnapshot, false);
                }
            }
            if ($endpoint->selection_mode === 'sequence') {
                $this->restoreSequenceOrder($endpoint);
            }

            return $endpoint->refresh()->load(['tags', 'environmentOverrides']);
        }

        if ($type === 'response') {
            // Revisions created before callback support have no callback keys.
            // Restoring one returns callback settings to their original defaults.
            $defaults = [
                'external_label' => null,
                ...app(FaultConfigurationValidator::class)->defaults(),
                'sequence_order' => null,
                'is_default' => false,
                'response_rules' => [],
                'callback_enabled' => false,
                'callback_url' => null,
                'callback_method' => 'POST',
                'callback_headers' => null,
                'callback_body' => null,
                'callback_delay_ms' => 0,
                'callback_delay_max_ms' => null,
                'callback_retry' => 1,
                'callback_backoff_ms' => 1000,
                'callback_timeout_ms' => 5000,
                'callback_signing_enabled' => false,
                'callback_signing_secret' => null,
                'callback_signature_header' => 'X-MockDeck-Signature',
            ];
            $snapshot = array_replace($defaults, $snapshot);
            foreach ($defaults as $key => $default) {
                if ($default !== null && $snapshot[$key] === null) {
                    $snapshot[$key] = $default;
                }
            }
            if ((int) $snapshot['callback_retry'] < 1) {
                $snapshot['callback_retry'] = $defaults['callback_retry'];
            }
            if ((int) $snapshot['callback_timeout_ms'] < 1) {
                $snapshot['callback_timeout_ms'] = $defaults['callback_timeout_ms'];
            }
            $response = MockResponse::query()->find((int) $snapshot['id']);
            if ($response === null) {
                $response = new MockResponse;
                $response->id = (int) $snapshot['id'];
                $response->uuid = $snapshot['uuid'];
                $response->mock_endpoint_id = (int) $snapshot['mock_endpoint_id'];
            }
            $endpoint = MockEndpoint::query()->whereKey($response->mock_endpoint_id)->lockForUpdate()->firstOrFail();
            if ($snapshot['is_default']) {
                foreach ($endpoint->responses()->where('is_default', true)->whereKeyNot($response->id)->get() as $sibling) {
                    $before = $this->snapshot($sibling);
                    $sibling->update(['is_default' => false]);
                    $this->recordIfChanged($sibling, $before, 'rollback', note: 'Fallback changed by response revision restore');
                }
            }
            $response->fill(collect($snapshot)->except(['id', 'uuid', 'mock_endpoint_id', 'callback_signing_secret', 'response_rules', 'endpoint_call_state', 'call_states', 'import_response_pool'])->all())->save();
            $response->rules()->delete();
            $response->rules()->createMany($snapshot['response_rules']);
            if ($adjustSequence && $endpoint->selection_mode === 'sequence') {
                $this->restoreSequenceOrder($endpoint, $response, $snapshot['sequence_order']);
            }
            if (array_key_exists('callback_signing_secret', $snapshot)) {
                // Snapshots contain ciphertext; avoid encrypting it a second time.
                DB::table('mock_responses')->where('id', $response->id)->update([
                    'callback_signing_secret' => $snapshot['callback_signing_secret'],
                ]);
            }

            return $response->refresh();
        }

        throw new InvalidArgumentException('Unsupported revision entity type.');
    }

    /** Keep sequence positions contiguous when a restored response changes its place. */
    private function restoreSequenceOrder(MockEndpoint $endpoint, ?MockResponse $restored = null, ?int $position = null): void
    {
        $responses = $endpoint->responses()->with('rules')->lockForUpdate()->get()
            ->reject(fn (MockResponse $response): bool => $restored !== null && $response->id === $restored->id)
            ->sort(fn (MockResponse $left, MockResponse $right): int => [
                $left->sequence_order ?? PHP_INT_MAX, $left->uuid,
            ] <=> [$right->sequence_order ?? PHP_INT_MAX, $right->uuid])
            ->values()->all();
        if ($restored !== null) {
            // Old snapshots without an order join the end of the current sequence.
            $position = max(0, min($position ?? count($responses), count($responses)));
            array_splice($responses, $position, 0, [$restored]);
        }
        foreach ($responses as $order => $response) {
            if ($response->sequence_order === $order) {
                continue;
            }
            $before = $this->snapshot($response);
            $response->update(['sequence_order' => $order]);
            if ($restored === null || $response->id !== $restored->id) {
                $this->recordIfChanged($response, $before, 'rollback', note: 'Sequence order adjusted by revision restore');
            }
        }
    }

    private function validateSelection(MockEndpoint $endpoint): void
    {
        app(SelectionConfigurationValidator::class)->validate(
            $endpoint->toArray(),
            $endpoint->responses()->with('rules')->get()->map(fn (MockResponse $response): array => $this->snapshot($response))->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private function diffPayload(Revision $before, array $after, array $to): array
    {
        return [
            'entity_type' => $before->entity_type,
            'entity_id' => $before->entity_id,
            'from' => ['kind' => 'revision', 'id' => $before->id, 'version' => $before->version_number],
            'to' => $to,
            'changes' => $this->differ->diff($before->snapshot, $after),
        ];
    }

    private function assertSameEntity(Revision $before, Revision $after): void
    {
        if ($before->entity_type !== $after->entity_type || $before->entity_id !== $after->entity_id) {
            throw new InvalidArgumentException('Revisions must belong to the same entity.');
        }
    }

    /** @param array<string, mixed> $value */
    private function normalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        if (array_is_list($value)) {
            sort($value);
        } else {
            ksort($value);
        }

        return $value;
    }

    private function short(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        if ($value === null) {
            return '—';
        }
        if (is_array($value)) {
            return count($value).' '.Str::plural('item', count($value));
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '“'.Str::limit((string) $value, 24).'”';
    }
}
