<?php

namespace App\Livewire\Admin\Concerns;

use App\Livewire\Admin\EndpointForm;
use App\Services\Environments\EnvironmentContext;
use App\Services\Response\ResponseSelectionService;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait EditsResponseSelection
{
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'callback')) {
            $this->publishCallbackValidity();
        }
        if (preg_match('/^(selectionMode|sequenceOnExhaust|responseRules|fallbackResponseId|responseOrder)(\.|$)/', $property)) {
            $this->publishSelectionValidity();
        }
    }

    private function publishSelectionValidity(): void
    {
        $responses = $this->endpoint()->responses()->get()->map(fn ($response): array => [
            'sequence_order' => array_search($response->id, $this->responseOrder, true),
            'is_default' => $response->id === $this->fallbackResponseId,
            'response_rules' => array_map(static function (array $rule): array {
                if (is_string($rule['priority'] ?? null) && ctype_digit($rule['priority'])) {
                    $rule['priority'] = (int) $rule['priority'];
                }

                return $rule;
            }, $this->responseRules[$response->id] ?? []),
        ])->all();
        $valid = $responses !== [];
        try {
            app(SelectionConfigurationValidator::class)->validate([
                'selection_mode' => $this->selectionMode, 'sequence_on_exhaust' => $this->sequenceOnExhaust,
            ], $responses);
        } catch (ValidationException) {
            $valid = false;
        }
        $this->dispatch('response-selection-validity', endpointId: $this->endpointId, valid: $valid)
            ->to(EndpointForm::class);
    }

    public string $selectionMode = 'weighted';

    public string $sequenceOnExhaust = 'repeat_last';

    /** @var list<int> */
    public array $responseOrder = [];

    public ?int $fallbackResponseId = null;

    /** @var array<int, list<array<string, mixed>>> */
    public array $responseRules = [];

    public function setSelectionMode(string $mode): void
    {
        if (in_array($mode, ['weighted', 'sequence', 'rule'], true)) {
            $this->selectionMode = $mode;
            $this->resetErrorBag('selection_mode');
            $this->publishSelectionValidity();
        }
    }

    public function setFallback(int $id): void
    {
        $this->endpoint()->responses()->findOrFail($id);
        $this->fallbackResponseId = $id;
        $this->publishSelectionValidity();
    }

    public function addRule(int $responseId): void
    {
        $this->endpoint()->responses()->findOrFail($responseId);
        $this->responseRules[$responseId][] = [
            'field_type' => 'header', 'field_name' => '', 'operator' => 'equals',
            'value' => '', 'priority' => collect($this->responseRules)->flatten(1)->max('priority') + 1,
        ];
        $this->publishSelectionValidity();
    }

    public function removeRule(int $responseId, int $index): void
    {
        $this->endpoint()->responses()->findOrFail($responseId);
        if (isset($this->responseRules[$responseId][$index])) {
            array_splice($this->responseRules[$responseId], $index, 1);
            $this->publishSelectionValidity();
        }
    }

    public function moveSelectionResponse(int $index, int $direction): void
    {
        $this->reorderSelectionResponse($index, $index + $direction);
    }

    private function reorderSelectionResponse(int $from, int $to): void
    {
        if (! isset($this->responseOrder[$from], $this->responseOrder[$to]) || $from === $to) {
            return;
        }
        $row = array_splice($this->responseOrder, $from, 1)[0];
        array_splice($this->responseOrder, $to, 0, [$row]);
        if ($this->selectionMode === 'rule') {
            $priority = 0;
            foreach ($this->responseOrder as $id) {
                foreach ($this->responseRules[$id] ?? [] as $index => $rule) {
                    $this->responseRules[$id][$index]['priority'] = $priority++;
                }
            }
        }
    }

    public function saveSelection(): void
    {
        DB::transaction(function (): void {
            $endpoint = $this->endpoint()->newQuery()->lockForUpdate()->findOrFail($this->endpointId);
            $responses = $endpoint->responses()->with('rules')->lockForUpdate()->get();
            $actual = $responses->modelKeys();
            $draft = array_map('intval', $this->responseOrder);
            sort($actual);
            $sortedDraft = $draft;
            sort($sortedDraft);
            if ($actual !== $sortedDraft) {
                throw ValidationException::withMessages(['selection_mode' => 'Responses changed. Reload before saving selection.']);
            }

            $config = ['selection_mode' => $this->selectionMode,
                'sequence_on_exhaust' => $this->selectionMode === 'sequence' ? $this->sequenceOnExhaust : null];
            $rows = $responses->map(fn ($response): array => [
                'id' => $response->id,
                'sequence_order' => $this->selectionMode === 'sequence' ? array_search($response->id, $draft, true) : null,
                'is_default' => $response->id === $this->fallbackResponseId,
                'response_rules' => array_map(static function ($rule): array {
                    if (! is_array($rule)) {
                        return [];
                    }
                    $rule = array_intersect_key($rule, array_flip(['field_type', 'field_name', 'operator', 'value', 'priority']));
                    if (is_string($rule['priority'] ?? null) && ctype_digit($rule['priority'])) {
                        $rule['priority'] = (int) $rule['priority'];
                    }

                    return $rule;
                }, array_values($this->responseRules[$response->id] ?? [])),
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
        $this->loadSelection();
        $this->resetValidation();
        if (! $this->savingDrafts) {
            $this->dispatch('toast', message: 'Response selection saved. Runtime counters were retained.');
        }
    }

    public function resetSequence(): void
    {
        app(ResponseSelectionService::class)->resetSequence($this->endpoint(), app(EnvironmentContext::class)->active());
        $this->dispatch('toast', message: 'Sequence reset for the active environment. Match count was retained.');
    }

    private function loadSelection(): void
    {
        $endpoint = $this->endpoint();
        $this->selectionMode = $endpoint->selection_mode ?? 'weighted';
        $this->sequenceOnExhaust = $endpoint->sequence_on_exhaust ?? 'repeat_last';
        $responses = $endpoint->responses()->with('rules')->get();
        $this->responseOrder = $responses->sortBy(fn ($response) => $this->selectionMode === 'sequence'
            ? ($response->sequence_order ?? PHP_INT_MAX)
            : ($response->rules->min('priority') ?? PHP_INT_MAX))->modelKeys();
        $this->responseOrder = array_values($this->responseOrder);
        $this->fallbackResponseId = $responses->firstWhere('is_default', true)?->id;
        $this->responseRules = $responses->mapWithKeys(fn ($response) => [$response->id => $response->rules->map(fn ($rule) => $rule->only([
            'field_type', 'field_name', 'operator', 'value', 'priority',
        ]))->all()])->all();
        $this->savedSelectionFingerprint = $this->selectionFingerprint();
        $this->publishSelectionValidity();
    }
}
