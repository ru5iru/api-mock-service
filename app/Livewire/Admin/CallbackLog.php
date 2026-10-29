<?php

namespace App\Livewire\Admin;

use App\Models\CallbackAttempt;
use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpAuditRedactor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class CallbackLog extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'response_id', except: '')]
    public string $responseId = '';

    #[Url(as: 'request_log_id', except: '')]
    public string $requestLogId = '';

    #[Url(as: 'time_range', except: '1h')]
    public string $timeRange = '1h';

    public string $message = '';

    public bool $paused = false;

    public ?int $selectedAttemptId = null;

    public function inspectAttempt(?int $id): void
    {
        $this->selectedAttemptId = $id;
    }

    public string $clock = 'local';

    public function togglePaused(): void
    {
        $this->paused = ! $this->paused;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->method = '';
        $this->status = '';
        $this->responseId = '';
        $this->requestLogId = '';
        $this->timeRange = '1h';
    }

    public function resend(int $attemptId): void
    {
        $attempt = CallbackAttempt::query()->findOrFail($attemptId);
        if ($attempt->response_id === null || $attempt->response === null) {
            $this->message = 'Cannot resend: the response was deleted.';

            return;
        }

        app(CallbackDispatcher::class)->enqueue(
            $attempt->response_id, $attempt->request_log_id,
            app(EnvironmentContext::class)->active()->id, [], $attempt,
        );
        $this->message = 'Resend queued. The original attempt remains unchanged.';
    }

    public function render(): View
    {
        $cutoff = match ($this->timeRange) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            default => null,
        };
        $rows = CallbackAttempt::query()->with('response:id,external_label')
            ->when(in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true), fn ($query) => $query->where('method', $this->method))
            ->when(in_array($this->status, ['pending', 'success', 'failed', 'timeout'], true), fn ($query) => $query->where('status', $this->status))
            ->when(ctype_digit($this->responseId) && $this->responseId !== '', fn ($query) => $query->where('response_id', (int) $this->responseId))
            ->when($this->requestLogId !== '', fn ($query) => $query->where('request_log_id', $this->requestLogId))
            ->when($cutoff !== null, fn ($query) => $query->where('created_at', '>=', $cutoff))
            ->latest()->limit(100)->get();
        $redactor = app(McpAuditRedactor::class);
        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $rows = $rows->filter(fn (CallbackAttempt $attempt): bool => str_contains(
                mb_strtolower($redactor->redact($attempt->target_url ?? '').' '.$attempt->request_log_id), $needle,
            ));
        }

        return view('livewire.admin.callback-log', ['rows' => $rows, 'redactor' => $redactor]);
    }
}
