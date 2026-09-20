<?php

namespace App\Livewire\Admin;

use App\Services\Logging\LogTailer;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LogViewer extends Component
{
    public int $limit = 50;

    public string $match = 'all';

    public string $method = '';

    public string $status = 'all';

    public function clearFilters(): void
    {
        $this->reset(['method', 'match', 'status']);
        $this->match = 'all';
        $this->status = 'all';
    }

    public function render(): View
    {
        $limit = in_array($this->limit, [25, 50, 100], true) ? $this->limit : 50;
        $match = in_array($this->match, ['all', 'matched', 'missed', 'fallback'], true) ? $this->match : 'all';
        $method = in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
            ? $this->method
            : '';
        $status = in_array($this->status, ['all', '2xx', '3xx', '4xx', '5xx'], true) ? $this->status : 'all';

        $events = app(LogTailer::class)->recent(500);
        $events = array_values(array_filter($events, static function (array $event) use ($match, $method, $status): bool {
            if ($method !== '' && ($event['method'] ?? null) !== $method) {
                return false;
            }

            if ($match === 'matched' && ! ($event['matched'] ?? false)) {
                return false;
            }

            if ($match === 'missed' && ($event['matched'] ?? false)) {
                return false;
            }

            if ($match === 'fallback' && ($event['match_tier'] ?? null) !== 'fallback') {
                return false;
            }

            if ($status !== 'all') {
                $statusCode = (int) ($event['status_code'] ?? 0);
                if (intdiv($statusCode, 100).'xx' !== $status) {
                    return false;
                }
            }

            return true;
        }));

        return view('livewire.admin.log-viewer', [
            'events' => array_slice($events, 0, $limit),
        ]);
    }
}
