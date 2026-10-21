<?php

namespace App\Services\Mutations;

use App\Models\MockEndpoint;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResponsePoolMutationService
{
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
