<div class="log-detail-grid">
                                    <section>
                                        <h3>{{ $event['method'] ?? '—' }} {{ $event['_path'] }}</h3>
                                        <h4>Recorded request event</h4>
                                        <p class="field-help">Method, URL, match, status, response ID and Environment are recorded at request time.</p>
                                        <dl class="detail-list">
                                            <div><dt>Request ID</dt><dd><code>{{ $event['request_id'] ?? 'Unavailable' }}</code></dd></div>
                                            <div><dt>Host</dt><dd>{{ $event['_host'] ?: 'Unavailable' }}</dd></div>
                                            <div><dt>Signature version</dt><dd>{{ $event['matched_variant'] ?? 'No match' }}</dd></div>
                                            <div><dt>Response</dt><dd>{{ isset($event['response_id']) ? '#'.$event['response_id'] : 'None selected' }}</dd></div>
                                            @if (isset($event['selection_mode']))<div><dt>Selection mode</dt><dd>{{ $event['selection_mode'] }}</dd></div>@endif
                                            @if (isset($event['fault_applied']))<div><dt>Injected fault</dt><dd>{{ $event['fault_applied'] }} · {{ $event['fault_delay_ms'] ?? 0 }} ms extra delay</dd></div>@endif
                                            @if (isset($event['selection_reason']))<div><dt>Selection reason</dt><dd>{{ $event['selection_reason'] }}</dd></div>@endif
                                            @if (($event['context_warning'] ?? null) === 'request_body_context_omitted')<div><dt>Template context</dt><dd>Body and JSON values omitted: {{ number_format($event['body_bytes'] ?? 0) }} bytes exceeds the {{ number_format($event['context_limit_bytes'] ?? 65536) }} byte limit. Other tokens were rendered normally.</dd></div>@endif
                                            @if (isset($event['response_id']) && isset($event['request_id']))
                                                <div><dt>Callbacks</dt><dd><a class="table-link" href="{{ route('dashboard.logs.index', ['type' => 'callbacks', 'request_log_id' => $event['request_id']]) }}" wire:navigate x-on:click.stop>View callback attempts</a></dd></div>
                                            @endif
                                            <div><dt>Environment</dt><dd>{{ $event['environment'] ?? '—' }}</dd></div>
                                            <div><dt>Delay</dt><dd>{{ $event['delay_ms'] ?? 0 }} ms</dd></div>
                                            @if (($event['_repeat_count'] ?? 1) > 1)
                                                <div><dt>Grouped</dt><dd>{{ $event['_repeat_count'] }} consecutive identical requests</dd></div>
                                            @endif
                                        </dl>
                                        <p class="field-help">The reconstructed cURL contains method and URL only; add headers and body before using it.</p>
                                    </section>
                                    <section>
                                        <h3>Current configuration references</h3>
                                        <p class="field-help">Endpoint names and nearest configured candidates use current configuration.</p>
                                        @if (in_array($tier, ['none', 'fallback'], true))
                                            {{-- TODO: Add masked headers, body, computed hash, and candidate diffs when the log event contract exposes them. --}}
                                            <h4>{{ $tier === 'none' ? 'No endpoint accepted this signature' : 'A normalized fallback was used' }}</h4>
                                            <p>Method and path/query are compared below. Request headers, body, and the computed hash are not present in the current log event, so deeper field-level differences cannot be reconstructed.</p>
                                            @if ($event['_nearest'] !== [])
                                                <div class="nearest-matches">
                                                    <strong>Nearest current configured candidates</strong>
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
                                            @if ($tier === 'none' && ($event['_nearest'][0]['method_matches'] ?? false) && ($event['_nearest'][0]['path_matches'] ?? false))
                                                <p class="info-note" role="note" data-cookie-matching-hint>If this request came from a browser, check whether it carries cookies that this endpoint's signature does not expect (Matching → Ignore cookies).</p>
                                            @endif
                                            <a class="button button-primary button-small" href="{{ route('dashboard.endpoints.create', ['curl' => $event['_reconstructed_curl']]) }}" wire:navigate>Create mock from this request</a>
                                        @else
                                            <h4>{{ $event['_endpoint_name'] ?? 'Matched endpoint' }}</h4>
                                            <p>The recorded event reports a hash match. Open the current endpoint to inspect its canonical signature and response pool.</p>
                                            <a class="button button-secondary button-small" href="{{ route('dashboard.endpoints.edit', $event['endpoint_id']) }}" wire:navigate>Open endpoint</a>
                                        @endif
                                    </section>
                                </div>
