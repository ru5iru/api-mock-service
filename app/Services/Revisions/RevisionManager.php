<?php

namespace App\Services\Revisions;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Models\Tag;
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
                'name' => $entity->name,
                'enabled' => (bool) $entity->enabled,
                'priority' => (int) $entity->priority,
                'method' => $entity->method,
                'raw_curl' => $entity->raw_curl,
                'normalized_curl' => $entity->normalized_curl,
                'curl_hash' => $entity->curl_hash,
                'signature_version' => (int) $entity->signature_version,
                'exclude_cookies' => (bool) $entity->exclude_cookies,
                'exclude_auth' => (bool) $entity->exclude_auth,
                'exclude_headers' => (bool) $entity->exclude_headers,
                'tags' => $entity->tags->modelKeys(),
                'environment_overrides' => $entity->environmentOverrides
                    ->mapWithKeys(static fn ($environment): array => [
                        (string) $environment->id => (bool) $environment->pivot->enabled,
                    ])->all(),
            ]);
        }

        if ($entity instanceof MockResponse) {
            return $this->normalize([
                'id' => $entity->id,
                'mock_endpoint_id' => $entity->mock_endpoint_id,
                'uuid' => $entity->uuid,
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
                'weight' => (int) $entity->weight,
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

        return $this->diffPayload(
            $revision,
            $this->snapshot($entity),
            ['kind' => 'current', 'id' => $entity->getKey(), 'version' => null],
        );
    }

    public function restore(Revision $revision): Revision
    {
        return DB::transaction(function () use ($revision): Revision {
            $entity = $this->applySnapshot($revision->entity_type, $revision->snapshot);

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
    private function applySnapshot(string $type, array $snapshot): Model
    {
        if ($type === 'endpoint') {
            $endpoint = MockEndpoint::query()->findOrFail((int) $snapshot['id']);
            $attributes = collect($snapshot)->except(['id', 'uuid', 'tags', 'environment_overrides'])->all();
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

            return $endpoint->refresh()->load(['tags', 'environmentOverrides']);
        }

        if ($type === 'response') {
            $response = MockResponse::query()->find((int) $snapshot['id']);
            if ($response === null) {
                $response = new MockResponse;
                $response->id = (int) $snapshot['id'];
                $response->uuid = $snapshot['uuid'];
                $response->mock_endpoint_id = (int) $snapshot['mock_endpoint_id'];
            }
            $response->fill(collect($snapshot)->except(['id', 'uuid', 'mock_endpoint_id'])->all())->save();

            return $response->refresh();
        }

        throw new InvalidArgumentException('Unsupported revision entity type.');
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
