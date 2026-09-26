<?php

namespace App\Jobs;

use App\Models\CallbackAttempt;
use App\Models\MockResponse;
use App\Services\Callbacks\CallbackDelivery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DeliverCallback implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /** @param array<string, mixed> $requestContext */
    public function __construct(
        public int $responseId,
        public ?string $requestLogId,
        public int $environmentId,
        public array $requestContext,
        public ?int $resendAttemptId = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('callbacks');
    }

    public function handle(CallbackDelivery $delivery): void
    {
        try {
            $response = MockResponse::query()->find($this->responseId);
            if ($response === null) {
                return;
            }
            $resend = $this->resendAttemptId !== null
                ? CallbackAttempt::query()->where('response_id', $response->id)->find($this->resendAttemptId)
                : null;
            if ($this->resendAttemptId !== null && $resend === null) {
                return;
            }
            $delivery->deliver($response, $this->requestLogId, $this->environmentId, $this->requestContext, $resend);
        } catch (Throwable $exception) {
            // The callback worker must never crash the primary mock application.
            error_log('MockDeck callback delivery failed: '.$exception::class);
        }
    }
}
