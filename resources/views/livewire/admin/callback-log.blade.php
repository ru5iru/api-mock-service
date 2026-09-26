<section class="card log-container callback-log" wire:poll.5s>
    <div class="log-filter-panel">
        <div class="log-filters callback-filters">
            <label class="select-field"><span class="select-caption">Method</span><select wire:model.live="method" aria-label="Callback method"><option value="">All methods</option>@foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb)<option value="{{ $verb }}">{{ $verb }}</option>@endforeach</select></label>
            <label class="select-field"><span class="select-caption">Status</span><select wire:model.live="status" aria-label="Callback status"><option value="">All statuses</option>@foreach (['pending', 'success', 'failed', 'timeout'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</select></label>
            <label class="select-field"><span class="select-caption">Time</span><select wire:model.live="timeRange" aria-label="Callback time range"><option value="15m">Last 15 minutes</option><option value="1h">Last hour</option><option value="24h">Last 24 hours</option><option value="all">All available</option></select></label>
            <label class="field"><span class="sr-only">Search callbacks</span><input type="search" wire:model.live.debounce.350ms="search" placeholder="Search target or request ID"></label>
            <button class="text-button" type="button" wire:click="clearFilters">Clear filters</button>
        </div>
    </div>
    @if ($message !== '') <p class="info-note" role="status">{{ $message }}</p> @endif
    <div class="table-scroll log-table-scroll">
        <table class="log-table">
            <thead><tr><th scope="col">Time</th><th scope="col">Target</th><th scope="col">Method</th><th scope="col">Attempt</th><th scope="col">Status</th><th scope="col" class="numeric">Duration</th><th scope="col">Retry of</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @php($previousDay = null)
                @forelse ($rows as $attempt)
                    @php($day = $attempt->created_at->toDateString())
                    @if ($day !== $previousDay)
                        <tr class="day-separator"><th scope="rowgroup" colspan="8">{{ $attempt->created_at->isToday() ? 'Today' : $attempt->created_at->format('M j, Y') }}</th></tr>
                        @php($previousDay = $day)
                    @endif
                    <tr wire:key="callback-attempt-{{ $attempt->id }}">
                        <td class="nowrap"><time title="{{ $attempt->created_at->toIso8601String() }}">{{ $attempt->created_at->diffForHumans(short: true) }}</time></td>
                        <td><code>{{ Str::limit($attempt->response?->callback_url ?? '[response deleted]', 75) }}</code>
                            @if ($attempt->request_log_id)<a class="table-link" href="{{ route('dashboard.requests.index') }}?search={{ urlencode($attempt->request_log_id) }}" wire:navigate>Request log</a>@endif
                        </td>
                        <td><span class="method-badge method-{{ strtolower($attempt->method) }}">{{ $attempt->method }}</span></td>
                        <td>{{ $attempt->attempt_number }}</td>
                        <td><span class="state-chip {{ $attempt->status === 'success' ? 'enabled' : 'disabled' }}">{{ ucfirst($attempt->status) }}{{ $attempt->http_status ? ' · '.$attempt->http_status : '' }}</span></td>
                        <td class="numeric">{{ $attempt->duration_ms === null ? '—' : $attempt->duration_ms.' ms' }}</td>
                        <td>{{ $attempt->retry_of_id ? 'Retry #'.$attempt->retry_of_id : ($attempt->resend_of_id ? 'Resend #'.$attempt->resend_of_id : '—') }}</td>
                        <td><button class="icon-button" type="button" wire:click="resend({{ $attempt->id }})" aria-label="Resend callback attempt {{ $attempt->id }}">Resend</button></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="mini-empty-state"><strong>No callback attempts</strong><span>Choose another filter or enable a callback on a response.</span></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
