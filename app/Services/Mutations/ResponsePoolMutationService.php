<?php

namespace App\Services\Mutations;

use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResponsePoolMutationService
{
    /** Shared dry-run/write validation; keep the existing selection grammar and messages. */
    public function validate(ResponsePoolInput $input): array
    {
        app(SelectionConfigurationValidator::class)->validate($input->selection(), array_map(fn (ResponsePoolEntry $entry) => $entry->selection(), $input->responses));

        return array_map(fn (ResponsePoolEntry $entry) => app(ResponseConfigurationValidator::class)->validate($entry->content), $input->responses);
    }

    /** Atomically replace a validated final pool, permitting fallback swaps and reordering. */
    public function replace(int $endpointId, ResponsePoolInput $input, ?TestRun $run = null): void
    {
        $attributes = $this->validate($input);
        DB::transaction(function () use ($endpointId, $input, $run, $attributes): void {
            $endpoint = MockEndpoint::query()->withoutGlobalScope('ordinary')->where('test_run_id', $run?->id)->lockForUpdate()->findOrFail($endpointId);
            $responses = $endpoint->responses()->with('rules')->lockForUpdate()->get()->keyBy('id');
            $ids = array_values(array_filter(array_map(fn (ResponsePoolEntry $entry) => $entry->id, $input->responses), fn ($id) => $id !== null));
            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $responses->modelKeys()) !== []) {
                throw ValidationException::withMessages(['responses' => 'Responses changed. Reload before saving selection.']);
            }
            $revisions = app(RevisionManager::class);
            $beforeEndpoint = $revisions->snapshot($endpoint);
            // Validate the whole target pool first: intermediate zero/two-fallback
            // states are private to this transaction, never a separate save contract.
            foreach ($responses as $response) {
                if (! in_array($response->id, $ids, true)) {
                    $response->delete();
                }
            }
            foreach ($input->responses as $index => $entry) {
                $response = $entry->id === null ? $endpoint->responses()->make() : $responses->get($entry->id);
                $before = $response->exists ? $revisions->snapshot($response) : null;
                if ($entry->writeContent) {
                    $response->fill($attributes[$index]);
                }
                $response->fill(['sequence_order' => $entry->sequenceOrder, 'is_default' => $entry->isDefault])->save();
                $rules = array_map(function (array $rule): array {
                    return ['field_type' => $rule['field_type'], 'field_name' => $rule['field_name'], 'operator' => $rule['operator'],
                        'value' => $rule['operator'] === 'exists' ? null : $rule['value'], 'priority' => $rule['priority']];
                }, $entry->ruleConditions);
                $existingRules = $response->rules()->get()->map(fn ($rule) => $rule->only(['field_type', 'field_name', 'operator', 'value', 'priority']))->all();
                // Stable ordering keeps a no-op patch a no-op in Version History.
                usort($rules, fn ($a, $b) => $a['priority'] <=> $b['priority']);
                if ($rules !== $existingRules) {
                    $response->rules()->delete();
                    $response->rules()->createMany($rules);
                }
                $response->unsetRelation('rules');
                if ($before !== null) {
                    $revisions->recordIfChanged($response, $before);
                }
            }
            $endpoint->update($input->selection());
            $revisions->recordIfChanged($endpoint, $beforeEndpoint);
        }, 3);
    }

    public function save(int $endpointId, SelectionInput $input): void
    {
        DB::transaction(function () use ($endpointId, $input): void {
            $endpoint = MockEndpoint::query()->lockForUpdate()->findOrFail($endpointId);
            $responses = $endpoint->responses()->with('rules')->lockForUpdate()->get();
            $actual = $responses->modelKeys();
            $draft = array_map('intval', $input->responseOrder);
            sort($actual);
            $sortedDraft = $draft;
            sort($sortedDraft);
            if ($actual !== $sortedDraft) {
                throw ValidationException::withMessages(['selection_mode' => 'Responses changed. Reload before saving selection.']);
            }

            $config = ['selection_mode' => $input->selectionMode,
                'sequence_on_exhaust' => $input->selectionMode === 'sequence' ? $input->sequenceOnExhaust : null];
            $rows = $responses->map(fn ($response): array => [
                'id' => $response->id,
                'sequence_order' => $input->selectionMode === 'sequence' ? array_search($response->id, $draft, true) : null,
                'is_default' => $response->id === $input->fallbackResponseId,
                'response_rules' => array_map(static function ($rule): array {
                    if (! is_array($rule)) {
                        return [];
                    }
                    $rule = array_intersect_key($rule, array_flip(['field_type', 'field_name', 'operator', 'value', 'priority']));
                    if (is_string($rule['priority'] ?? null) && ctype_digit($rule['priority'])) {
                        $rule['priority'] = (int) $rule['priority'];
                    }

                    return $rule;
                }, array_values($input->responseRules[$response->id] ?? [])),
            ])->all();
            app(SelectionConfigurationValidator::class)->validate($config, $rows);
            $revisions = app(RevisionManager::class);
            $beforeEndpoint = $revisions->snapshot($endpoint);
            $endpoint->update($config);
            foreach ($rows as $row) {
                $response = $responses->firstWhere('id', $row['id']);
                $before = $revisions->snapshot($response);
                $response->update(['sequence_order' => $row['sequence_order'], 'is_default' => $row['is_default']]);
                $response->rules()->delete();
                $response->rules()->createMany(array_map(function (array $rule): array {
                    $rule['value'] = $rule['operator'] === 'exists' ? null : $rule['value'];

                    return $rule;
                }, $row['response_rules']));
                $response->unsetRelation('rules');
                $revisions->recordIfChanged($response, $before);
            }
            $revisions->recordIfChanged($endpoint, $beforeEndpoint);
        }, 3);
    }

    public function ready(int $endpointId, SelectionInput $input): bool
    {
        $responses = MockEndpoint::findOrFail($endpointId)->responses()->get()->map(fn ($response): array => [
            'sequence_order' => array_search($response->id, $input->responseOrder, true),
            'is_default' => $response->id === $input->fallbackResponseId,
            'response_rules' => array_map(static function (array $rule): array {
                if (is_string($rule['priority'] ?? null) && ctype_digit($rule['priority'])) {
                    $rule['priority'] = (int) $rule['priority'];
                }

                return $rule;
            }, $input->responseRules[$response->id] ?? []),
        ])->all();
        $valid = $responses !== [];
        try {
            app(SelectionConfigurationValidator::class)->validate([
                'selection_mode' => $input->selectionMode, 'sequence_on_exhaust' => $input->sequenceOnExhaust,
            ], $responses);
        } catch (ValidationException) {
            $valid = false;
        }

        return $valid;
    }
}
