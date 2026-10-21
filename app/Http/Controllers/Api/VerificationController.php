<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Verification\CallAssertionInput;
use App\Services\Verification\CallAssertionService;
use App\Services\Verification\CallDigestRecorder;
use App\Services\Verification\RuntimeStateReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VerificationController extends Controller
{
    public function calls(Request $request, MockEndpoint $endpoint): JsonResponse
    {
        $environment = $this->environment($request);
        $state = $this->state($endpoint, $environment)->first();
        $recent = $state?->recent_call_digests ?? [];
        $data = [
            'endpoint' => $endpoint->uuid,
            'environment' => $environment->name,
            'total_match_count' => $state?->total_match_count ?? 0,
            'last_matched_at' => $state?->last_matched_at?->toIso8601String(),
            'recent_calls' => $recent,
            'window' => ['capacity' => CallDigestRecorder::CAPACITY, 'retained_calls' => count($recent), 'unretained_calls' => max(0, ($state?->total_match_count ?? 0) - count($recent))],
        ];
        if ($endpoint->selection_mode === 'sequence') {
            $data['sequence_position'] = $state?->sequence_position ?? 0;
        }

        return response()->json($data);
    }

    public function assertCalls(Request $request, MockEndpoint $endpoint, CallAssertionService $assertions): JsonResponse
    {
        $environment = $this->environment($request);
        $input = app(CallAssertionInput::class)->validate($request->all());
        $result = $assertions->evaluate($this->state($endpoint, $environment)->first(), $input['matching'] ?? [], $input['comparator'], (int) $input['expect_count']);
        $result['message'] = "Endpoint {$endpoint->uuid}, environment {$environment->name}: ".$result['message'];

        return response()->json($result, $result['passed'] ? 200 : 422);
    }

    public function reset(Request $request, MockEndpoint $endpoint): JsonResponse
    {
        $environment = $this->environment($request);
        $updated = app(RuntimeStateReset::class)->reset($this->state($endpoint, $environment));

        return response()->json(['reset' => true, 'environment' => $environment->name, 'endpoint' => $endpoint->uuid, 'state_rows_reset' => $updated]);
    }

    public function resetAll(Request $request): JsonResponse
    {
        $environment = $this->environment($request);
        // A single atomic UPDATE serializes with selection's row updates and never
        // creates state rows for untouched endpoints or changes configuration.
        $updated = DB::transaction(fn (): int => app(RuntimeStateReset::class)->reset(EndpointCallState::query()->whereNull('test_run_id')->where('environment_id', $environment->id)), 5);

        return response()->json(['reset' => true, 'environment' => $environment->name, 'state_rows_reset' => $updated]);
    }

    private function environment(Request $request): Environment
    {
        $input = $request->validate(['environment' => ['required', 'string', 'max:255']]);

        return Environment::query()->where('name', $input['environment'])->firstOrFail();
    }

    private function state(MockEndpoint $endpoint, Environment $environment)
    {
        return EndpointCallState::query()->whereNull('test_run_id')->where('endpoint_id', $endpoint->id)->where('environment_id', $environment->id);
    }
}
