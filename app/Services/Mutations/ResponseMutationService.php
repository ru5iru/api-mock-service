<?php

namespace App\Services\Mutations;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\TestRun;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ResponseMutationService
{
    public function save(int $endpointId, ResponseInput $input, ?int $responseId = null, ?TestRun $run = null): MockResponse
    {
        $attributes = app(ResponseConfigurationValidator::class)->validate($input);

        return DB::transaction(function () use ($attributes, $endpointId, $responseId, $run): MockResponse {
            $endpoint = MockEndpoint::query()->withoutGlobalScope('ordinary')->where('test_run_id', $run?->id)->lockForUpdate()->findOrFail($endpointId);
            $pool = $endpoint->responses()->with('rules')->get();
            $selectionRows = $pool->map(fn ($item) => array_merge($item->toArray(), ['response_rules' => $item->rules->toArray()]))->all();
            if ($responseId === null) {
                $selectionRows[] = ['sequence_order' => $endpoint->selection_mode === 'sequence' ? $pool->count() : null, 'is_default' => false, 'response_rules' => []];
            }
            app(SelectionConfigurationValidator::class)->validate($endpoint->toArray(), $selectionRows);
            if ($responseId === null) {
                $newAttributes = $attributes;
                if ($endpoint->selection_mode === 'sequence') {
                    $newAttributes['sequence_order'] = $pool->count();
                }

                return $endpoint->responses()->create($newAttributes);
            }

            $response = $endpoint->responses()->findOrFail($responseId);
            $revisions = app(RevisionManager::class);
            $before = $revisions->snapshot($response);
            $response->update($attributes);
            $revisions->recordIfChanged($response, $before);

            return $response;
        }, 3);

    }

    public function saveCallback(int $endpointId, int $responseId, array $values, bool $secretSet = false): MockResponse
    {
        $validated = Validator::make($values, app(CallbackConfigurationValidator::class)->rules())->validate();
        $response = MockEndpoint::findOrFail($endpointId)->responses()->findOrFail($responseId);
        $attributes = app(CallbackConfigurationValidator::class)->attributes([...$validated, 'locale' => $response->locale ?? 'en'], $secretSet);

        return DB::transaction(function () use ($attributes, $endpointId, $responseId): MockResponse {
            $response = MockEndpoint::findOrFail($endpointId)->responses()->lockForUpdate()->findOrFail($responseId);
            $revisions = app(RevisionManager::class);
            $before = $revisions->snapshot($response);
            $response->update($attributes);
            $revisions->recordIfChanged($response, $before);

            return $response;
        });
    }

    public function delete(int $endpointId, int $responseId, ?TestRun $run = null): void
    {
        DB::transaction(function () use ($endpointId, $responseId, $run): void {
            $endpoint = MockEndpoint::query()->withoutGlobalScope('ordinary')->where('test_run_id', $run?->id)->lockForUpdate()->findOrFail($endpointId);
            $response = $endpoint->responses()->findOrFail($responseId);
            if ($endpoint->selection_mode === 'rule' && $response->is_default) {
                throw ValidationException::withMessages([
                    'selection_mode' => 'Deleting the fallback would leave rule mode invalid. Select and save another fallback first.',
                ]);
            }
            $response->delete();
            if ($endpoint->selection_mode === 'sequence') {
                $revisions = app(RevisionManager::class);
                foreach ($endpoint->responses()->reorder()->orderBy('sequence_order')->orderBy('id')->get() as $position => $item) {
                    $before = $revisions->snapshot($item);
                    $item->update(['sequence_order' => $position]);
                    $revisions->recordIfChanged($item, $before);
                }
            }
        }, 3);
    }
}
