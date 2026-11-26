<?php

namespace App\Jobs;

use App\Models\CallbackAttempt;
use App\Models\MockResponse;
use App\Services\Callbacks\CallbackDelivery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeliverCallback implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Maximum number of queue-level attempts before the job is moved to failed_jobs.
     * The delivery loop inside CallbackDelivery handles per-attempt retries and
     * exponential backoff independently of this queue-level tries value.
     */
    public int $tries = 3;

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

    /** @return int[] */
    public function backoff(): array
    {
        // Queue-level retry delays (seconds): 30 s, 2 min.
        return [30, 120];
    }

    public function handle(CallbackDelivery $delivery): void
    {
        $response = MockResponse::query()->withoutGlobalScope('ordinary')->with('endpoint.testRun.credential')->find($this->responseId);
        if ($response === null) {
            return;
        }
        $run = $response->endpoint?->testRun;
        if ($run !== null && ($run->status !== 'active' || $run->credential === null || $run->credential->revoked_at !== null)) {
            return;
        }
        $resend = $this->resendAttemptId !== null
            ? CallbackAttempt::query()->where('response_id', $response->id)->find($this->resendAttemptId)
            : null;
        if ($this->resendAttemptId !== null && $resend === null) {
            return;
        }

        // Let exceptions propagate so Laravel can record the failed job and apply
        // $this->backoff() retry delays. CallbackDelivery::deliver() records its
        // own CallbackAttempt rows and already swallows network-level errors;
        // only configuration or infrastructure failures should surface here.
        $delivery->deliver($response, $this->requestLogId, $this->environmentId, $this->requestContext, $resend);
    }

    /**
     * Handle a job that has exhausted all queue-level attempts.
     * The job is recorded in failed_jobs by Laravel's default mechanism;
     * this method provides an additional structured log entry.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('MockDeck callback job exhausted all attempts', [
            'response_id'      => $this->responseId,
            'request_log_id'   => $this->requestLogId,
            'environment_id'   => $this->environmentId,
            'resend_attempt_id' => $this->resendAttemptId,
            'exception_class'  => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }
}
