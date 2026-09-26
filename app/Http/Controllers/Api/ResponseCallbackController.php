<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockResponse;
use App\Services\Callbacks\CallbackDelivery;
use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Environments\EnvironmentContext;
use App\Services\Revisions\RevisionManager;
use App\Services\Templates\TemplateCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResponseCallbackController extends Controller
{
    public function show(MockResponse $response): JsonResponse
    {
        return response()->json(['data' => $this->safeConfiguration($response)]);
    }

    public function update(Request $request, MockResponse $response, RevisionManager $revisions, CallbackDelivery $delivery, TemplateCompiler $templates): JsonResponse
    {
        $data = $request->validate([
            'callback_enabled' => ['sometimes', 'boolean'],
            'callback_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'callback_method' => ['sometimes', 'in:POST,PUT,PATCH,DELETE'],
            'callback_headers' => ['sometimes', 'nullable', 'array'],
            'callback_body' => ['sometimes', 'nullable', 'string', 'max:262144'],
            'callback_delay_ms' => ['sometimes', 'integer', 'between:0,30000'],
            'callback_delay_max_ms' => ['sometimes', 'nullable', 'integer', 'between:0,30000'],
            'callback_retry' => ['sometimes', 'integer', 'between:1,5'],
            'callback_backoff_ms' => ['sometimes', 'integer', 'between:0,30000'],
            'callback_timeout_ms' => ['sometimes', 'integer', 'between:100,10000'],
            'callback_signing_enabled' => ['sometimes', 'boolean'],
            'callback_signing_secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'callback_signature_header' => ['sometimes', 'regex:/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', 'max:128'],
        ]);

        $enabled = $data['callback_enabled'] ?? $response->callback_enabled;
        $url = array_key_exists('callback_url', $data) ? $data['callback_url'] : $response->callback_url;
        if ($enabled) {
            if (! is_string($url) || $url === '') {
                throw ValidationException::withMessages(['callback_url' => 'Enter a callback URL before enabling callbacks.']);
            }
            $sample = preg_replace('/\{\{[^{}]+\}\}|\$request\.[A-Za-z0-9_.-]+/', 'example.test', $url);
            try {
                $delivery->validateUrl($sample);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['callback_url' => 'Use a well-formed HTTP(S) URL.']);
            }
        }

        $maximum = array_key_exists('callback_delay_max_ms', $data) ? $data['callback_delay_max_ms'] : $response->callback_delay_max_ms;
        if ($maximum !== null && $maximum < ($data['callback_delay_ms'] ?? $response->callback_delay_ms)) {
            throw ValidationException::withMessages(['callback_delay_max_ms' => 'Maximum delay cannot be less than minimum delay.']);
        }
        $headers = $data['callback_headers'] ?? $response->callback_headers ?? [];
        if ($headers !== [] && array_is_list($headers)) {
            throw ValidationException::withMessages(['callback_headers' => 'Headers must be a JSON object.']);
        }
        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', $name) !== 1
                || ! is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
                throw ValidationException::withMessages(['callback_headers' => 'Headers must contain valid single-line names and string values.']);
            }
        }
        $body = $data['callback_body'] ?? $response->callback_body;
        if ($enabled) {
            $validation = $templates->compile((string) ($body ?: '{}'), (string) ($response->locale ?: 'en'), true);
            if ($validation->hasErrors()) {
                throw ValidationException::withMessages(['callback_body' => $validation->issues[0]->message]);
            }
        }

        $secret = array_key_exists('callback_signing_secret', $data) && $data['callback_signing_secret'] !== ''
            ? $data['callback_signing_secret'] : $response->callback_signing_secret;
        if (($data['callback_signing_enabled'] ?? $response->callback_signing_enabled) && ($secret === null || $secret === '')) {
            throw ValidationException::withMessages(['callback_signing_secret' => 'Enter a secret before enabling signing.']);
        }
        if (($data['callback_signing_secret'] ?? null) === '') {
            unset($data['callback_signing_secret']); // Empty input means retain the write-only secret.
        }

        DB::transaction(function () use ($response, $revisions, $data): void {
            $before = $revisions->snapshot($response);
            $response->update($data);
            $revisions->recordIfChanged($response, $before);
        }, 3);

        return response()->json(['data' => $this->safeConfiguration($response->refresh())]);
    }

    public function test(MockResponse $response, CallbackDispatcher $callbacks, EnvironmentContext $environment): JsonResponse
    {
        if (! $response->callback_enabled) {
            throw ValidationException::withMessages(['callback_enabled' => 'Enable and save callbacks before sending a test.']);
        }

        $id = 'callback-test-'.Str::uuid();
        $callbacks->enqueue($response->id, $id, $environment->active()->id, [
            'id' => $id,
            'method' => 'POST',
            'url' => url('/api/responses/'.$response->id.'/callback/test'),
            'body' => '{}',
            'json' => [],
            'headers' => [],
        ]);

        return response()->json(['data' => ['status' => 'queued', 'request_log_id' => $id]], 202);
    }

    /** @return array<string, mixed> */
    public function safeConfiguration(MockResponse $response): array
    {
        return [
            'callback_enabled' => $response->callback_enabled,
            'callback_url' => $response->callback_url,
            'callback_method' => $response->callback_method,
            'callback_headers' => $response->callback_headers ?? new \stdClass,
            'callback_body' => $response->callback_body,
            'callback_delay_ms' => $response->callback_delay_ms,
            'callback_delay_max_ms' => $response->callback_delay_max_ms,
            'callback_retry' => $response->callback_retry,
            'callback_backoff_ms' => $response->callback_backoff_ms,
            'callback_timeout_ms' => $response->callback_timeout_ms,
            'callback_signing_enabled' => $response->callback_signing_enabled,
            'callback_signing_secret' => null,
            'callback_signing_secret_set' => $response->callback_signing_secret !== null,
            'callback_signature_header' => $response->callback_signature_header,
        ];
    }
}
