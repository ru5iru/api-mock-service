<div class="card log-card" wire:poll.5s>
    <div class="log-toolbar">
        <div class="live-indicator"><i></i> Refreshing every 5 seconds</div>
        <label>
            Show
            <select wire:model.live="limit">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </label>
    </div>

    <div class="table-scroll">
        <table class="log-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Request</th>
                    <th>Match</th>
                    <th>Endpoint</th>
                    <th>Status</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="nowrap">{{ isset($event['timestamp']) ? Str::after($event['timestamp'], 'T') : '—' }}</td>
                        <td>
                            <span class="mini-method">{{ $event['method'] ?? '—' }}</span>
                            <code>{{ $event['url'] ?? '—' }}</code>
                        </td>
                        <td>
                            <span class="match-chip {{ ($event['matched'] ?? false) ? 'matched' : 'missed' }}">
                                {{ $event['match_tier'] ?? 'none' }}{{ isset($event['matched_variant']) ? ' · '.$event['matched_variant'] : '' }}
                            </span>
                        </td>
                        <td>{{ $event['endpoint_id'] ?? '—' }}</td>
                        <td><strong>{{ $event['status_code'] ?? '—' }}</strong></td>
                        <td class="nowrap">{{ $event['duration_ms'] ?? '—' }} ms</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty-table">No invocation events yet. Send a request to any non-dashboard path.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
