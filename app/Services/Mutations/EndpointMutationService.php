<?php

namespace App\Services\Mutations;

use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EndpointMutationService
{
    public function save(EndpointInput $input, ?int $endpointId = null, ?TestRun $run = null): MockEndpoint
    {
        ['parsed' => $parsed, 'variant' => $variant, 'matching' => $matching] = app(EndpointConfigurationValidator::class)->validate($input, $endpointId, $run);

        $endpoint = $endpointId === null
            ? new MockEndpoint(['test_run_id' => $run?->id])
            : MockEndpoint::query()->withoutGlobalScope('ordinary')->where('test_run_id', $run?->id)->findOrFail($endpointId);
        $created = $endpointId === null;
        $displayName = trim($input->name) ?: app(EndpointRequestService::class)->deriveName($parsed);
        $revisions = app(RevisionManager::class);
        $before = $created ? null : $revisions->snapshot($endpoint);

        DB::transaction(function () use ($input, $endpoint, $displayName, $parsed, $variant, $matching, $revisions, $before): void {
            $endpoint->fill([
                'collection_id' => $input->collectionId === '' ? null : (int) $input->collectionId,
                'name' => $displayName,
                'enabled' => $input->enabled,
                'priority' => $input->priority,
                'method' => $parsed->method,
                'raw_curl' => $input->rawCurl,
                'normalized_curl' => $variant->normalized,
                'curl_hash' => $variant->hash,
                'signature_version' => $variant->name === 'V6' ? 6 : ($endpoint->exists && $endpoint->signature_version !== 6 ? $endpoint->signature_version : 2),
                ...$matching,
                'exclude_cookies' => $input->excludeHeaders ? false : $input->excludeCookies,
                'exclude_auth' => $input->excludeHeaders ? false : $input->excludeAuth,
                'exclude_headers' => $input->excludeHeaders,
            ])->save();
            $endpoint->tags()->sync(array_map('intval', $input->tagIds));
            $overrides = collect($input->environmentOverrides)
                ->filter(static fn (string $enabled): bool => $enabled !== '')
                ->mapWithKeys(static fn (string $enabled, int|string $environmentId): array => [
                    (int) $environmentId => ['enabled' => $enabled === '1'],
                ])->all();
            $endpoint->environmentOverrides()->sync($overrides);
            if ($before !== null) {
                $revisions->recordIfChanged($endpoint, $before);
            }
        }, 3);

        return $endpoint;
    }

    public function rename(?int $endpointId, string $value): string
    {
        $name = trim($value);
        Validator::make(['name' => $name], ['name' => ['nullable', 'string', 'max:255']])->validate();
        if ($endpointId !== null) {
            DB::transaction(function () use ($name, $endpointId): void {
                $endpoint = MockEndpoint::query()->lockForUpdate()->findOrFail($endpointId);
                $revisions = app(RevisionManager::class);
                $before = $revisions->snapshot($endpoint);
                $endpoint->update(['name' => $name === '' ? null : $name]);
                $revisions->recordIfChanged($endpoint, $before);
            });
        }

        return $name;
    }

    public function delete(int $endpointId): void
    {
        MockEndpoint::query()->findOrFail($endpointId)->delete();
    }

    public function duplicate(int $endpointId): MockEndpoint
    {
        $source = MockEndpoint::query()->with(['responses.rules', 'tags', 'environmentOverrides'])->findOrFail($endpointId);

        return DB::transaction(function () use ($source): MockEndpoint {
            $copy = $source->replicate(['uuid']);
            $copy->name = 'Copy of '.$source->displayName();
            $copy->enabled = false;
            $copy->save();

            foreach ($source->responses as $response) {
                $newResponse = $copy->responses()->save($response->replicate(['uuid']));
                $newResponse->rules()->createMany($response->rules->map(fn ($rule) => $rule->only(['field_type', 'field_name', 'operator', 'value', 'priority']))->all());
            }
            $copy->tags()->sync($source->tags->modelKeys());
            $copy->environmentOverrides()->sync($source->environmentOverrides->mapWithKeys(
                static fn ($environment): array => [$environment->id => ['enabled' => (bool) $environment->pivot->enabled]],
            )->all());

            return $copy;
        });

    }

    /** @param list<int> $ids */
    public function deleteMany(array $ids): void
    {
        MockEndpoint::query()->whereKey($ids)->delete();
    }

    public function reviseMany(array $ids, callable $change): void
    {
        DB::transaction(function () use ($ids, $change): void {
            $revisions = app(RevisionManager::class);
            foreach (MockEndpoint::query()->whereKey($ids)->lockForUpdate()->get() as $endpoint) {
                $before = $revisions->snapshot($endpoint);
                $change($endpoint);
                $revisions->recordIfChanged($endpoint, $before);
            }
        }, 3);
    }
}
