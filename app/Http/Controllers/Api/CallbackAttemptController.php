<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallbackAttempt;
use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CallbackAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'response_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,success,failed,timeout'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'request_log_id' => ['nullable', 'string', 'max:128'],
        ]);
        $attempts = CallbackAttempt::query()->with('response:id,callback_url')
            ->when(isset($filters['response_id']), fn ($query) => $query->where('response_id', $filters['response_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['method']), fn ($query) => $query->where('method', $filters['method']))
            ->when(isset($filters['request_log_id']), fn ($query) => $query->where('request_log_id', $filters['request_log_id']))
            ->latest()->limit(100)->get();

        return response()->json(['data' => $attempts->map(static fn (CallbackAttempt $attempt): array => [
            'id' => $attempt->id,
            'response_id' => $attempt->response_id,
            'request_log_id' => $attempt->request_log_id,
            'resend_of_id' => $attempt->resend_of_id,
            'retry_of_id' => $attempt->retry_of_id,
            'attempt_number' => $attempt->attempt_number,
            'target' => $attempt->response?->callback_url ?? '[response deleted]',
            'method' => $attempt->method,
            'status' => $attempt->status,
            'http_status' => $attempt->http_status,
            'duration_ms' => $attempt->duration_ms,
            'error' => $attempt->error,
            'created_at' => $attempt->created_at,
            // Resolved URLs, headers, and bodies may contain secret environment values.
        ])->all()]);
    }

    public function resend(CallbackAttempt $attempt, CallbackDispatcher $callbacks, EnvironmentContext $environment): JsonResponse
    {
        if ($attempt->response_id === null || $attempt->response === null) {
            return response()->json(['error' => 'The source response no longer exists.'], 404);
        }

        $callbacks->enqueue($attempt->response_id, $attempt->request_log_id, $environment->active()->id, [], $attempt);

        return response()->json(['data' => ['status' => 'queued', 'resend_of_id' => $attempt->id]], 202);
    }
}
