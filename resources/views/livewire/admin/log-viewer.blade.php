<div class="card log-card" wire:poll.5s>
    <div class="log-toolbar">
        <div class="live-indicator"><i></i> Refreshing every 5 seconds</div>
        <div class="log-filters">
            <label class="select-field">
                <span class="select-caption">Method</span>
                <select wire:model.live="method">
                    <option value="">All methods</option>
                    @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="select-field">
                <span class="select-caption">Match</span>
                <select wire:model.live="match">
                    <option value="all">All matches</option>
                    <option value="matched">Matched</option>
                    <option value="missed">Missed</option>
                    <option value="fallback">Fallback only</option>
                </select>
            </label>
            <label class="select-field">
                <span class="select-caption">Response</span>
                <select wire:model.live="status">
                    <option value="all">All statuses</option>
                    <option value="2xx">2xx</option>
                    <option value="3xx">3xx</option>
                    <option value="4xx">4xx</option>
                    <option value="5xx">5xx</option>
                </select>
            </label>
            <label class="select-field select-field-compact">
                <span class="select-caption">Rows</span>
                <select wire:model.live="limit">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
            @if ($method !== '' || $match !== 'all' || $status !== 'all')
                <button class="text-button filter-reset" type="button" wire:click="clearFilters">Clear filters</button>
            @endif
        </div>
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
                            <code title="Request ID: {{ $event['request_id'] ?? 'unavailable' }}">{{ $event['url'] ?? '—' }}</code>
                        </td>
                        <td>
                            <span class="match-chip {{ ($event['matched'] ?? false) ? 'matched' : 'missed' }}">
                                {{ $event['match_tier'] ?? 'none' }}{{ isset($event['matched_variant']) ? ' · '.$event['matched_variant'] : '' }}
                            </span>
                        </td>
                        <td>{{ $event['endpoint_id'] ?? '—' }}</td>
                        <td><strong class="status-code status-code-{{ isset($event['status_code']) ? intdiv((int) $event['status_code'], 100) : 'unknown' }}xx">{{ $event['status_code'] ?? '—' }}</strong></td>
                        <td class="nowrap">{{ $event['duration_ms'] ?? '—' }} ms</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty-table">No invocation events match the current filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
