<?php

namespace App\Services\Callbacks;

use App\Jobs\DeliverCallback;
use App\Models\CallbackAttempt;
use App\Models\MockResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class CallbackDispatcher
{
    public function afterResponse(MockResponse $response, Request $request, string $requestId, int $environmentId): void
    {
        if (! $response->callback_enabled) {
            return;
        }

        $context = [
            'id' => $requestId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'body' => substr($request->getContent(), 0, 65536),
            'headers' => array_map(static fn (array $values): string => (string) ($values[0] ?? ''), $request->headers->all()),
        ];
        $json = json_decode(substr($request->getContent(), 0, 65536), true);
        if (is_array($json)) {
            $context['json'] = $json;
        }

        $this->enqueue($response->id, $requestId, $environmentId, $context);
    }

    /** @param array<string, mixed> $context */
    public function enqueue(int $responseId, ?string $requestId, int $environmentId, array $context = [], ?CallbackAttempt $resend = null): void
    {
        // Laravel runs terminating callbacks after sending the response to the client.
        app()->terminating(static function () use ($responseId, $requestId, $environmentId, $context, $resend): void {
            try {
                Queue::connection('database')->push(new DeliverCallback(
                    $responseId, $requestId, $environmentId, $context, $resend?->id,
                ), queue: 'callbacks');
            } catch (Throwable $exception) {
                // A down queue must not alter the already-sent mock response.
                error_log('MockDeck callback enqueue failed: '.$exception::class);
            }
        });
    }
}
