<section class="card log-card callback-log" data-log-viewer data-log-density="compact" @unless($paused) wire:poll.visible.5s @endunless>
    <div class="log-statusbar">
        <div class="live-state {{ $paused ? 'paused' : 'live' }}"><i aria-hidden="true"></i><strong>{{ $paused ? 'Paused' : 'Live' }}</strong><span>Updated <time datetime="{{ now()->toIso8601String() }}" data-relative-time>just now</time></span></div>
        <div class="log-live-actions"><button class="button button-tertiary button-small" type="button" wire:click="togglePaused">{{ $paused ? 'Resume refresh' : 'Pause refresh' }}</button></div>
    </div>
    <div class="log-filter-panel" aria-label="Callback log filters">
        <label class="search-field log-search">
            <span class="sr-only">Search callbacks</span>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <input type="search" wire:model.live.debounce.350ms="search" data-search-shortcut placeholder="Search target or request ID…">
        </label>
        <div class="log-filters callback-filters">
            <label class="select-field"><span class="select-caption">Method</span><select class="ui-select ui-select-filter" wire:model.live="method" aria-label="Callback method"><option value="">All methods</option>@foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb)<option value="{{ $verb }}">{{ $verb }}</option>@endforeach</select></label>
            <label class="select-field"><span class="select-caption">Status</span><select class="ui-select ui-select-filter" wire:model.live="status" aria-label="Callback status"><option value="">All statuses</option>@foreach (['pending', 'success', 'failed', 'timeout'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</select></label>
            <label class="select-field"><span class="select-caption">Time</span><select class="ui-select ui-select-filter" wire:model.live="timeRange" aria-label="Callback time range"><option value="15m">Last 15 minutes</option><option value="1h">Last hour</option><option value="24h">Last 24 hours</option><option value="all">All available</option></select></label>
        </div>
        <div class="quick-filter-row">
            <div class="segmented-control clock-toggle" role="group" aria-label="Callback timestamp display">
                <button class="{{ $clock === 'local' ? 'active' : '' }}" type="button" wire:click="$set('clock', 'local')" aria-pressed="{{ $clock === 'local' ? 'true' : 'false' }}">Local</button>
                <button class="{{ $clock === 'utc' ? 'active' : '' }}" type="button" wire:click="$set('clock', 'utc')" aria-pressed="{{ $clock === 'utc' ? 'true' : 'false' }}">UTC</button>
            </div>
            <div class="segmented-control density-toggle" role="group" aria-label="Callback log row density">
                <button type="button" data-density-option="compact" aria-pressed="true">Compact</button>
                <button type="button" data-density-option="comfortable" aria-pressed="false">Comfortable</button>
            </div>
            @if ($search !== '' || $method !== '' || $status !== '' || $responseId !== '' || $requestLogId !== '' || $timeRange !== '1h')
                <button class="text-button" type="button" wire:click="clearFilters">Clear filters</button>
            @endif
        </div>
    </div>
    @if ($responseId !== '' || $requestLogId !== '')
        <div class="quick-filter-row" aria-label="Callback scope">
            @if ($responseId !== '')<button type="button" class="tag-chip selectable" wire:click="$set('responseId', '')" aria-label="Remove response scope">Response #{{ $responseId }} · Remove</button>@endif
            @if ($requestLogId !== '')<button type="button" class="tag-chip selectable" wire:click="$set('requestLogId', '')" aria-label="Remove request scope">Request {{ $requestLogId }} · Remove</button>@endif
        </div>
    @endif
    <p class="field-help">Showing up to 100 recent attempts matching these filters. Search checks recorded targets and request IDs in this window.</p>
    @if ($message !== '') <p class="info-note" role="status">{{ $message }}</p> @endif
    <div class="table-scroll log-table-scroll">
        <table class="log-table">
            <thead><tr><th scope="col">Status</th><th scope="col">Target / details</th><th scope="col">Time</th><th scope="col">Environment</th><th scope="col">Method</th><th scope="col">Attempt</th><th scope="col" class="numeric">Duration</th><th scope="col">Retry of</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @php($previousDay = null)
                @forelse ($rows as $attempt)
                    @php($day = $attempt->created_at->toDateString())
                    @if ($day !== $previousDay)
                        <tr class="day-separator"><th scope="rowgroup" colspan="9">{{ $attempt->created_at->isToday() ? 'Today' : $attempt->created_at->format('M j, Y') }}</th></tr>
                        @php($previousDay = $day)
                    @endif
                    <tr wire:key="callback-attempt-{{ $attempt->id }}">
                        <td><x-badge :variant="$attempt->status === 'success' ? 'success' : ($attempt->status === 'pending' ? 'info' : 'danger')">{{ ucfirst($attempt->status) }}{{ $attempt->http_status ? ' · '.$attempt->http_status : '' }}</x-badge></td>

                        <td><code>{{ Str::limit($redactor->redact($attempt->target_url ?? '[target unavailable]'), 75) }}</code>
                            <details class="normalized-details" data-disclosure wire:ignore.self wire:key="callback-detail-{{ $attempt->id }}">
                                <summary><x-disclosure-chevron /> Attempt #{{ $attempt->id }}</summary>
                                <p class="field-help">Recorded attempt fields retain the delivery evidence. The response label uses current configuration.</p>
                                <dl class="definition-list">
                                    <div><dt>Recorded target</dt><dd><code>{{ $redactor->redact($attempt->target_url ?? '[target unavailable]') }}</code></dd></div>
                                    <div><dt>Response / current label</dt><dd>#{{ $attempt->response_id ?? 'deleted' }} · {{ $attempt->response?->external_label ?? 'Saved response' }}</dd></div>
                                    <div><dt>Request</dt><dd>{{ $attempt->request_log_id ?? 'No request reference' }}</dd></div>
                                    <div><dt>Outcome</dt><dd>{{ $attempt->error ? $redactor->redact($attempt->error) : ($attempt->http_status ? 'Receiver returned HTTP '.$attempt->http_status : 'No error detail recorded.') }}</dd></div>
                                    <div><dt>Lineage</dt><dd>{{ $attempt->retry_of_id ? 'Retry of #'.$attempt->retry_of_id : ($attempt->resend_of_id ? 'Resend of #'.$attempt->resend_of_id : 'Original attempt') }}</dd></div>
                                </dl>
                                <p class="field-help">Resend queues a new attempt using this attempt's captured target and payload. The original record is retained.</p>
                            </details>
                            @if ($attempt->request_log_id)<a class="table-link" href="{{ route('dashboard.logs.index', ['type' => 'requests', 'search' => $attempt->request_log_id]) }}" wire:navigate>Request log</a>@endif
                        </td>
                        <td class="nowrap"><time datetime="{{ $attempt->created_at->toIso8601String() }}" data-log-time data-clock="{{ $clock }}" title="{{ $attempt->created_at->toIso8601String() }}">{{ $attempt->created_at->diffForHumans(short: true) }}</time></td>
                        <td><x-badge>{{ $attempt->environment ?? '—' }}</x-badge></td>
                        <td><span class="method-badge method-{{ strtolower($attempt->method) }}">{{ $attempt->method }}</span></td>
                        <td>{{ $attempt->attempt_number }}</td>

                        <td class="numeric">{{ $attempt->duration_ms === null ? '—' : $attempt->duration_ms.' ms' }}</td>
                        <td>{{ $attempt->retry_of_id ? 'Retry #'.$attempt->retry_of_id : ($attempt->resend_of_id ? 'Resend #'.$attempt->resend_of_id : '—') }}</td>
                        <td><button class="icon-button" type="button" wire:click="resend({{ $attempt->id }})" aria-label="Resend callback attempt {{ $attempt->id }}">Resend</button></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="mini-empty-state"><strong>No callback attempts</strong><span>Choose another filter or enable a callback on a response.</span></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
