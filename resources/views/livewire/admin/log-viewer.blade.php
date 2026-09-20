<div
    class="card log-card"
    @if (! $paused && $expandedKey === null) wire:poll.visible.5s @endif
    data-log-viewer
    data-unmatched-timestamps="{{ json_encode($unmatchedTimestamps) }}"
    aria-live="polite"
>
    <div class="log-statusbar">
        <div class="live-state {{ ($paused || $expandedKey !== null) ? 'paused' : 'live' }}">
            <i aria-hidden="true"></i>
            <strong>{{ $paused ? 'Paused' : ($expandedKey !== null ? 'Detail open' : 'Live') }}</strong>
            <span>Updated <time datetime="{{ $lastUpdatedAt }}" data-relative-time>just now</time></span>
        </div>
        <div class="log-live-actions">
            <button class="button button-tertiary button-small" type="button" wire:click="togglePaused">
                {{ $paused ? 'Resume refresh' : 'Pause refresh' }}
            </button>
            @unless ($full)
                <a class="text-link" href="{{ route('dashboard.requests.index') }}" wire:navigate>View full request log →</a>
            @endunless
        </div>
    </div>

    @if ($full)
        <div class="log-filter-panel" aria-label="Request log filters">
            <label class="search-field log-search">
                <span class="sr-only">Search request log</span>
                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <input type="search" wire:model.live.debounce.300ms="search" data-search-shortcut placeholder="Search path, endpoint, or request ID…">
            </label>

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
                        <option value="all">All match types</option>
                        <option value="hash">Exact hash</option>
                        <option value="fallback">Fallback</option>
                        <option value="none">No match</option>
                    </select>
                </label>
                <label class="select-field endpoint-filter">
                    <span class="select-caption">Endpoint</span>
                    <select wire:model.live="endpoint">
                        <option value="all">All endpoints</option>
                        @foreach ($filterEndpoints as $filterEndpoint)
                            <option value="{{ $filterEndpoint->id }}">{{ $filterEndpoint->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="select-field">
                    <span class="select-caption">HTTP status</span>
                    <select wire:model.live="status">
                        <option value="all">All statuses</option>
                        <option value="2xx">2xx</option>
                        <option value="3xx">3xx</option>
                        <option value="4xx">4xx</option>
                        <option value="5xx">5xx</option>
                    </select>
                </label>
                <label class="select-field">
                    <span class="select-caption">Time range</span>
                    <select wire:model.live="timeRange">
                        <option value="15m">Last 15 minutes</option>
                        <option value="1h">Last hour</option>
                        <option value="24h">Last 24 hours</option>
                        <option value="all">All available</option>
                    </select>
                </label>
                <label class="select-field select-field-compact">
                    <span class="select-caption">Show</span>
                    <select wire:model.live="limit">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
            </div>

            <div class="quick-filter-row">
                <label class="quick-filter {{ $unmatchedOnly ? 'active' : '' }}">
                    <input class="sr-only" type="checkbox" wire:model.live="unmatchedOnly">
                    <span aria-hidden="true">!</span> Unmatched only
                </label>
                <label class="toggle-inline">
                    <input type="checkbox" wire:model.live="groupRepeats">
                    <span>Group repeats</span>
                </label>
                <div class="clock-toggle" role="group" aria-label="Timestamp display">
                    <button class="{{ $clock === 'local' ? 'active' : '' }}" type="button" wire:click="$set('clock', 'local')">Local</button>
                    <button class="{{ $clock === 'utc' ? 'active' : '' }}" type="button" wire:click="$set('clock', 'utc')">UTC</button>
                </div>
                @if ($search !== '' || $method !== '' || $match !== 'all' || $status !== 'all' || $endpoint !== 'all' || $timeRange !== '1h' || $unmatchedOnly)
                    <button class="text-button" type="button" wire:click="clearFilters">Clear filters</button>
                @endif
            </div>
        </div>
    @endif

    <div class="log-loading" wire:loading.delay.flex wire:target="search,method,match,status,endpoint,timeRange,limit,unmatchedOnly,groupRepeats,clearFilters">
        <span class="spinner" aria-hidden="true"></span>
        Updating request log…
    </div>

    <div class="table-scroll log-table-scroll" wire:loading.class="is-loading" wire:target="search,method,match,status,endpoint,timeRange,limit,unmatchedOnly,groupRepeats,clearFilters">
        <table class="log-table">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Request</th>
                    <th scope="col">Match <x-help-tip title="Match types" label="Hash is an exact signature match. Fallback is a normalized recovery match. None means no endpoint matched." /></th>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="numeric">Duration</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $previousDay = null;
                @endphp
                @forelse ($events as $event)
                    @php
                        $timestamp = $event['_timestamp'] ? \Illuminate\Support\Carbon::parse($event['_timestamp']) : null;
                        $dayKey = $timestamp?->toDateString() ?? 'unknown';
                        $dayLabel = $timestamp?->isToday() ? 'Today' : ($timestamp?->isYesterday() ? 'Yesterday' : ($timestamp?->format('D, M j, Y') ?? 'Unknown date'));
                        $tier = $event['match_tier'] ?? 'none';
                        $expanded = $expandedKey === $event['_key'];
                    @endphp
                    @if ($dayKey !== $previousDay)
                        <tr class="day-separator"><th colspan="6" scope="rowgroup">{{ $dayLabel }}</th></tr>
                        @php
                            $previousDay = $dayKey;
                        @endphp
                    @endif
                    <tr
                        class="log-row {{ $expanded ? 'expanded' : '' }}"
                        tabindex="0"
                        wire:key="log-{{ $event['_key'] }}"
                        wire:click="toggleExpanded('{{ $event['_key'] }}')"
                        wire:keydown.enter="toggleExpanded('{{ $event['_key'] }}')"
                        aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                    >
                        <td class="nowrap">
                            @if ($timestamp)
                                <time datetime="{{ $timestamp->toIso8601String() }}" data-log-time data-clock="{{ $clock }}" title="UTC: {{ $timestamp->utc()->format('Y-m-d H:i:s T') }}">
                                    {{ $timestamp->diffForHumans(short: true) }}
                                </time>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <div class="request-cell">
                                <span class="method-badge method-{{ strtolower($event['method'] ?? 'get') }}">{{ $event['method'] ?? '—' }}</span>
                                <code title="Host: {{ $event['_host'] ?: 'not captured' }} · Request ID: {{ $event['request_id'] ?? 'unavailable' }}">{{ $event['_path'] }}</code>
                                @if (($event['_repeat_count'] ?? 1) > 1)
                                    <span class="repeat-badge">×{{ $event['_repeat_count'] }}</span>
                                @endif
                                <button class="copy-row-control" type="button" data-copy-text="{{ $event['_reconstructed_curl'] }}" x-on:click.stop aria-label="Copy request curl">Copy</button>
                            </div>
                        </td>
                        <td>
                            <span class="match-chip match-{{ $tier }}">
                                <span aria-hidden="true">{{ $tier === 'hash' ? '✓' : ($tier === 'fallback' ? '~' : '!') }}</span>
                                {{ $tier === 'hash' ? 'Hash' : ($tier === 'fallback' ? 'Fallback' : 'None') }}
                            </span>
                        </td>
                        <td>
                            @if ($event['_endpoint_exists'])
                                <a class="table-link" href="{{ route('dashboard.endpoints.edit', $event['endpoint_id']) }}" wire:navigate x-on:click.stop>{{ $event['_endpoint_name'] }}</a>
                            @elseif (isset($event['endpoint_id']))
                                <span class="muted">Deleted #{{ $event['endpoint_id'] }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="status-result {{ $event['_mocked_server_response'] ? 'mocked' : 'status-result-'.intdiv((int) ($event['status_code'] ?? 0), 100).'xx' }}">
                                <span aria-hidden="true">{{ $event['_mocked_server_response'] ? '●' : ((int) ($event['status_code'] ?? 0) >= 400 ? '!' : '✓') }}</span>
                                {{ $event['status_code'] ?? '—' }} {{ $event['_status_label'] }}
                                @if ($event['_mocked_server_response'])
                                    <em title="This 5xx was intentionally returned by a matched mock response.">mocked</em>
                                @endif
                            </span>
                        </td>
                        <td class="numeric nowrap">{{ number_format((float) ($event['duration_ms'] ?? 0), 2) }} ms</td>
                    </tr>
                    @if ($expanded)
                        <tr class="log-detail-row" wire:key="detail-{{ $event['_key'] }}">
                            <td colspan="6">
                                <div class="log-detail-grid">
                                    <section>
                                        <span class="eyebrow">Captured request</span>
                                        <h3>{{ $event['method'] ?? '—' }} {{ $event['_path'] }}</h3>
                                        <dl class="detail-list">
                                            <div><dt>Request ID</dt><dd><code>{{ $event['request_id'] ?? 'Unavailable' }}</code></dd></div>
                                            <div><dt>Host</dt><dd>{{ $event['_host'] ?: 'Unavailable' }}</dd></div>
                                            <div><dt>Signature version</dt><dd>{{ $event['matched_variant'] ?? 'No match' }}</dd></div>
                                            <div><dt>Response</dt><dd>{{ isset($event['response_id']) ? '#'.$event['response_id'] : 'None selected' }}</dd></div>
                                            <div><dt>Delay</dt><dd>{{ $event['delay_ms'] ?? 0 }} ms</dd></div>
                                            @if (($event['_repeat_count'] ?? 1) > 1)
                                                <div><dt>Grouped</dt><dd>{{ $event['_repeat_count'] }} consecutive identical requests</dd></div>
                                            @endif
                                        </dl>
                                    </section>
                                    <section>
                                        @if (in_array($tier, ['none', 'fallback'], true))
                                            {{-- TODO: Add masked headers, body, computed hash, and candidate diffs when the log event contract exposes them. --}}
                                            <span class="eyebrow">Why no exact match</span>
                                            <h3>{{ $tier === 'none' ? 'No endpoint accepted this signature' : 'A normalized fallback was used' }}</h3>
                                            <p>Method and path/query are compared below. Request headers, body, and the computed hash are not present in the current log event, so deeper field-level differences cannot be reconstructed.</p>
                                            @if ($event['_nearest'] !== [])
                                                <div class="nearest-matches">
                                                    <strong>Nearest endpoint candidates</strong>
                                                    @foreach ($event['_nearest'] as $candidate)
                                                        <a href="{{ route('dashboard.endpoints.edit', $candidate['id']) }}" wire:navigate>
                                                            <span>{{ $candidate['name'] }}</span>
                                                            <small>
                                                                {{ $candidate['method_matches'] ? 'Method matches' : 'Method differs: '.$candidate['method'] }} ·
                                                                {{ $candidate['target_matches'] ? 'Path/query matches' : 'Path/query differs: '.$candidate['target'] }}
                                                            </small>
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <a class="button button-primary button-small" href="{{ route('dashboard.endpoints.create', ['curl' => $event['_reconstructed_curl']]) }}" wire:navigate>Create mock from this request</a>
                                        @else
                                            <span class="eyebrow">Exact match</span>
                                            <h3>{{ $event['_endpoint_name'] ?? 'Matched endpoint' }}</h3>
                                            <p>The stored hash matched this request. Open the endpoint to inspect its canonical signature and response pool.</p>
                                            <a class="button button-secondary button-small" href="{{ route('dashboard.endpoints.edit', $event['endpoint_id']) }}" wire:navigate>Open endpoint</a>
                                        @endif
                                    </section>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="empty-table">
                            @if ($hasAnyEvents)
                                <strong>No requests match the current filters.</strong>
                                <span>Clear the filters to return to all available events.</span>
                                <button class="button button-secondary button-small" type="button" wire:click="clearFilters">Clear filters</button>
                            @else
                                <strong>No requests captured yet.</strong>
                                <span>Invoke a configured mock to see timing and match diagnostics here.</span>
                                <button class="button button-secondary button-small" type="button" data-copy-text="curl 'http://localhost:18473/health'">Copy sample curl</button>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
