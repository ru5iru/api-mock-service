<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SequenceSelectionStrategy implements SelectionStrategyInterface
{
    public function select(MockEndpoint $endpoint, Collection $responses, Request $request, EndpointCallState $state): SelectionResult
    {
        $position = (int) $state->sequence_position;
        $count = $responses->count();
        if ($position >= $count) {
            $position = match ($endpoint->sequence_on_exhaust ?? 'repeat_last') {
                'loop' => $position % $count,
                'not_found' => null,
                default => $count - 1,
            };
        }
        if ($position === null) {
            return new SelectionResult(null, 'sequence_exhausted', $state->sequence_position);
        }
        $response = $responses->first(fn ($response) => $response->sequence_order === $position);

        return new SelectionResult($response, $response ? 'selected' : 'sequence_invalid_order', $state->sequence_position);
    }
}
