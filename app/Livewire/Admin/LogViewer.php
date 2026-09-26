<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Logging\LogTailer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LogViewer extends Component
{
    public bool $full = false;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: 'all')]
    public string $match = 'all';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'all')]
    public string $endpoint = 'all';

    #[Url(except: '1h')]
    public string $timeRange = '1h';

    #[Url(except: false)]
    public bool $unmatchedOnly = false;

    #[Url(except: 25)]
    public int $limit = 25;

    #[Url(except: true)]
    // Keep repeat grouping enabled by default in both the dashboard widget and full log.
    public bool $groupRepeats = true;

    public bool $paused = false;

    public string $clock = 'local';

    public ?string $expandedKey = null;

    public function mount(bool $full = false): void
    {
        $this->full = $full;
        if (! $full) {
            $this->limit = 10;
            $this->timeRange = 'all';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'method', 'match', 'status', 'endpoint', 'unmatchedOnly']);
        $this->match = 'all';
        $this->status = 'all';
        $this->endpoint = 'all';
        $this->timeRange = $this->full ? '1h' : 'all';
    }

    public function togglePaused(): void
    {
        $this->paused = ! $this->paused;
    }

    public function toggleExpanded(string $key): void
    {
        $this->expandedKey = $this->expandedKey === $key ? null : $key;
    }

    public function render(): View
    {
        $limit = in_array($this->limit, [10, 25, 50, 100], true) ? $this->limit : ($this->full ? 25 : 10);
        $match = in_array($this->match, ['all', 'hash', 'fallback', 'none'], true) ? $this->match : 'all';
        $method = in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
            ? $this->method
            : '';
        $status = in_array($this->status, ['all', '2xx', '3xx', '4xx', '5xx'], true) ? $this->status : 'all';
        $timeRange = in_array($this->timeRange, ['15m', '1h', '24h', 'all'], true) ? $this->timeRange : '1h';

        $rawEvents = app(LogTailer::class)->recent(500);
        $unmatchedTimestamps = array_values(array_filter(array_map(
            static fn (array $event): ?string => ($event['match_tier'] ?? 'none') === 'none' ? ($event['timestamp'] ?? null) : null,
            $rawEvents,
        )));

        $endpointIds = array_values(array_unique(array_filter(array_map(
            static fn (array $event): ?int => isset($event['endpoint_id']) ? (int) $event['endpoint_id'] : null,
            $rawEvents,
        ))));
        $endpoints = MockEndpoint::query()->withCount('responses')->whereKey($endpointIds)->get()->keyBy('id');
        $filterEndpoints = $this->full
            ? MockEndpoint::query()->orderBy('name')->orderBy('id')->get()
            : collect();
        $candidateEndpoints = $this->full ? $filterEndpoints : collect();

        $events = array_map(function (array $event) use ($endpoints, $candidateEndpoints): array {
            $url = (string) ($event['url'] ?? '');
            $parts = parse_url($url) ?: [];
            $path = (string) ($parts['path'] ?? '/');
            if (($parts['query'] ?? '') !== '') {
                $path .= '?'.$parts['query'];
            }
            $endpointId = isset($event['endpoint_id']) ? (int) $event['endpoint_id'] : null;
            $matchedEndpoint = $endpointId !== null ? $endpoints->get($endpointId) : null;
            $statusCode = (int) ($event['status_code'] ?? 0);
            try {
                $timestamp = isset($event['timestamp']) ? Carbon::parse($event['timestamp'])->toIso8601String() : null;
            } catch (Throwable) {
                $timestamp = null;
            }
            $event['_key'] = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            $event['_path'] = $path;
            $event['_host'] = (string) ($parts['host'] ?? '');
            $event['_endpoint_name'] = $matchedEndpoint?->displayName();
            $event['_endpoint_exists'] = $matchedEndpoint !== null;
            $event['_status_label'] = Response::$statusTexts[$statusCode] ?? 'Unknown';
            $event['_mocked_server_response'] = $statusCode >= 500 && ($event['match_tier'] ?? null) === 'hash';
            $event['_timestamp'] = $timestamp;
            $event['_repeat_count'] = 1;
            $event['_reconstructed_curl'] = sprintf(
                'curl --request %s %s',
                strtoupper((string) ($event['method'] ?? 'GET')),
                escapeshellarg($url),
            );
            $event['_nearest'] = in_array($event['match_tier'] ?? 'none', ['none', 'fallback'], true)
                ? $candidateEndpoints
                    ->map(function (MockEndpoint $candidate) use ($event, $path): array {
                        $methodMatches = strtoupper((string) ($event['method'] ?? '')) === strtoupper($candidate->method);
                        $candidateTarget = $candidate->requestTarget();

                        return [
                            'id' => $candidate->id,
                            'name' => $candidate->displayName(),
                            'method' => $candidate->method,
                            'target' => $candidateTarget,
                            'method_matches' => $methodMatches,
                            'target_matches' => $candidateTarget === $path,
                            'score' => ($methodMatches ? 0 : 30) + levenshtein(substr($path, 0, 255), substr($candidateTarget, 0, 255)),
                        ];
                    })
                    ->sortBy('score')
                    ->take(3)
                    ->values()
                    ->all()
                : [];

            return $event;
        }, $rawEvents);

        $cutoff = match ($timeRange) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            default => null,
        };
        $needle = Str::lower(trim($this->search));

        $events = array_values(array_filter($events, function (array $event) use ($method, $match, $status, $cutoff, $needle): bool {
            $tier = (string) ($event['match_tier'] ?? 'none');
            if ($method !== '' && ($event['method'] ?? null) !== $method) {
                return false;
            }
            if ($match !== 'all' && $tier !== $match) {
                return false;
            }
            if ($this->unmatchedOnly && $tier !== 'none') {
                return false;
            }
            if ($status !== 'all' && intdiv((int) ($event['status_code'] ?? 0), 100).'xx' !== $status) {
                return false;
            }
            if ($this->endpoint !== 'all' && (string) ($event['endpoint_id'] ?? '') !== $this->endpoint) {
                return false;
            }
            if ($cutoff !== null && $event['_timestamp'] !== null && Carbon::parse($event['_timestamp'])->lt($cutoff)) {
                return false;
            }
            if ($needle !== '') {
                $haystack = Str::lower(implode(' ', [
                    (string) ($event['method'] ?? ''),
                    (string) ($event['_path'] ?? ''),
                    (string) ($event['_host'] ?? ''),
                    (string) ($event['_endpoint_name'] ?? ''),
                    (string) ($event['request_id'] ?? ''),
                ]));

                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));

        if ($this->groupRepeats) {
            $events = $this->groupConsecutive($events);
        }

        return view('livewire.admin.log-viewer', [
            'events' => array_slice($events, 0, $limit),
            'filterEndpoints' => $filterEndpoints,
            'unmatchedTimestamps' => $unmatchedTimestamps,
            'lastUpdatedAt' => now()->toIso8601String(),
            'hasAnyEvents' => $rawEvents !== [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function groupConsecutive(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $identity = implode('|', [
                (string) ($event['method'] ?? ''),
                (string) ($event['_path'] ?? ''),
                (string) ($event['match_tier'] ?? ''),
                (string) ($event['endpoint_id'] ?? ''),
                (string) ($event['status_code'] ?? ''),
                (string) ($event['environment_id'] ?? $event['environment'] ?? ''),
            ]);
            $lastIndex = count($grouped) - 1;

            if ($lastIndex >= 0 && ($grouped[$lastIndex]['_identity'] ?? null) === $identity) {
                $grouped[$lastIndex]['_repeat_count']++;

                continue;
            }

            $event['_identity'] = $identity;
            $grouped[] = $event;
        }

        return $grouped;
    }
}
