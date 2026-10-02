<?php

namespace App\Services\Callbacks;

use App\Jobs\DeliverCallback;
use App\Models\CallbackAttempt;
use App\Models\MockResponse;
use App\Services\Templates\TemplateContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class CallbackDispatcher
{
    public function __construct(private readonly TemplateContext $contexts) {}

    public function afterResponse(MockResponse $response, Request $request, string $requestId, int $environmentId): void
    {
        if (! $response->callback_enabled) {
            return;
        }

        $context = $this->contexts->capture($request, $requestId);

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
