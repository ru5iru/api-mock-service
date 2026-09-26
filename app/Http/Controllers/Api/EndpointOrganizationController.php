<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Revisions\RevisionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EndpointOrganizationController extends Controller
{
    public function update(Request $request, MockEndpoint $endpoint, RevisionManager $revisions): JsonResponse
    {
        $data = $request->validate([
            'collection_id' => ['sometimes', 'nullable', 'integer', 'exists:collections,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'distinct', 'exists:tags,id'],
            'environment_overrides' => ['sometimes', 'array'],
            'environment_overrides.*' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('environment_overrides', $data)) {
            $keys = array_keys($data['environment_overrides']);
            $ids = array_values(array_unique(array_map('intval', $keys)));
            $keysAreIds = collect($keys)->every(static fn (int|string $key): bool => ctype_digit((string) $key) && (int) $key > 0);
            if (! $keysAreIds || Environment::query()->whereKey($ids)->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'environment_overrides' => 'Every environment override key must identify an existing environment.',
                ]);
            }
        }

        DB::transaction(function () use ($data, $endpoint, $revisions): void {
            $before = $revisions->snapshot($endpoint);
            if (array_key_exists('collection_id', $data)) {
                $endpoint->update(['collection_id' => $data['collection_id']]);
            }
            if (array_key_exists('tags', $data)) {
                $endpoint->tags()->sync($data['tags']);
            }
            if (array_key_exists('environment_overrides', $data)) {
                $sync = collect($data['environment_overrides'])
                    ->filter(static fn ($enabled): bool => $enabled !== null)
                    ->mapWithKeys(static fn ($enabled, $id): array => [(int) $id => ['enabled' => (bool) $enabled]])
                    ->all();
                $endpoint->environmentOverrides()->sync($sync);
            }
            $revisions->recordIfChanged($endpoint, $before);
        }, 3);

        return response()->json($endpoint->refresh()->load(['collection', 'tags', 'environmentOverrides']));
    }
}
