<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Verification\CallDigestRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResponseSelectionService
{
    public function __construct(
        private readonly WeightedSelectionStrategy $weighted,
        private readonly SequenceSelectionStrategy $sequence,
        private readonly RuleBasedSelectionStrategy $rules,
        private readonly CallDigestRecorder $digests,
    ) {}

    public function select(MockEndpoint $endpoint, Request $request, Environment $environment): SelectionResult
    {
        return DB::transaction(function () use ($endpoint, $request, $environment): SelectionResult {
            // Inserting before reading serializes SQLite writers too. ON CONFLICT
            // handles simultaneous first calls without aborting PostgreSQL transactions.
            DB::table('endpoint_call_state')->insertOrIgnore([
                'endpoint_id' => $endpoint->id,
                'environment_id' => $environment->id,
                'sequence_position' => 0,
                'total_match_count' => 0,
            ]);
            $state = $this->state($endpoint, $environment)->lockForUpdate()->firstOrFail();
            $responses = $endpoint->responses()->with('rules')->get();
            $result = $responses->isEmpty()
                ? new SelectionResult(null, 'no_responses')
                : $this->strategy($endpoint)->select($endpoint, $responses, $request, $state);
            $state->total_match_count++;
            $state->last_matched_at = now();
            // The verification ring and counters share the same row lock: no lost
            // digests, including faulted responses and exhausted sequences.
            $state->recent_call_digests = array_slice([
                ...($state->recent_call_digests ?? []),
                $this->digests->capture($request, $state->last_matched_at),
            ], -CallDigestRecorder::CAPACITY);
            if ($endpoint->selection_mode === 'sequence') {
                $state->sequence_position++;
            }
            $state->save();

            return $result;
        }, 5);
    }

    public function resetSequence(MockEndpoint $endpoint, Environment $environment): void
    {
        // One atomic update, no lazy state creation merely by opening/resetting an editor.
        $this->state($endpoint, $environment)->update(['sequence_position' => 0]);
    }

    public function preview(MockEndpoint $endpoint, Environment $environment): SelectionResult
    {
        $state = $this->state($endpoint, $environment)->first() ?? new EndpointCallState(['sequence_position' => 0]);
        $responses = $endpoint->responses()->with('rules')->get();
        if ($responses->isEmpty()) {
            return new SelectionResult(null, 'no_responses');
        }

        return $this->strategy($endpoint)->select($endpoint, $responses, Request::create('/'), $state);
    }

    private function state(MockEndpoint $endpoint, Environment $environment)
    {
        return EndpointCallState::query()->where('endpoint_id', $endpoint->id)->where('environment_id', $environment->id);
    }

    private function strategy(MockEndpoint $endpoint): SelectionStrategyInterface
    {
        return match ($endpoint->selection_mode ?? 'weighted') {
            'sequence' => $this->sequence,
            'rule' => $this->rules,
            default => $this->weighted,
        };
    }
}
