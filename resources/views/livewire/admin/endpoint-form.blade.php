<form wire:submit="save" class="endpoint-editor" data-unsaved-form>
    @php
        $requestReady = (bool) $preview && ! $duplicate && ! $isExample;
        $matchingReady = (bool) $preview;
        $responseReady = $endpointId && \App\Models\MockEndpoint::query()->find($endpointId)?->responses()->exists();
        $saveBlockReason = ! $preview
            ? 'Paste a valid curl command to continue.'
            : ($duplicate
                ? 'Resolve the duplicate signature before saving.'
                : ($isExample ? 'Replace the example curl before saving.' : ''));
        $requestState = $requestReady ? 'valid' : (($submitAttempted || in_array('request', $touchedSections, true)) ? 'attention' : 'neutral');
        $matchingState = $matchingReady ? 'valid' : (($submitAttempted || in_array('matching', $touchedSections, true)) ? 'attention' : 'neutral');
        $responseState = $responseReady ? 'valid' : (($submitAttempted || in_array('response', $touchedSections, true)) ? 'attention' : 'neutral');
        $stateSymbol = static fn (string $state): string => $state === 'valid' ? '✓' : ($state === 'attention' ? '!' : '');
        $statusMessage = $saveBlockReason !== ''
            ? $saveBlockReason
            : (! $responseReady
                ? 'Next: add a response so this endpoint can answer requests.'
                : 'Editing '.$derivedName.' · Ctrl/⌘ + Enter saves.');
    @endphp

    <nav class="flow-nav" aria-label="Endpoint sections" data-section-nav>
        <ol>
            <li><a href="#request-definition" data-section-link wire:click="touchSection('request')"><span>Request</span><i class="section-status {{ $requestState }}" aria-label="{{ $requestState === 'valid' ? 'Request is valid' : ($requestState === 'attention' ? 'Request needs attention' : 'Request not yet reviewed') }}">{{ $stateSymbol($requestState) }}</i></a></li>
            <li><a href="#matching-policy" data-section-link wire:click="touchSection('matching')"><span>Matching</span><i class="section-status {{ $matchingState }}" aria-label="{{ $matchingState === 'valid' ? 'Matching policy is ready' : ($matchingState === 'attention' ? 'Matching policy needs attention' : 'Matching policy not yet reviewed') }}">{{ $stateSymbol($matchingState) }}</i></a></li>
            <li>
                <a href="{{ $endpointId ? '#responses' : '#save-actions' }}" data-section-link wire:click="touchSection('response')"><span>Response</span><i class="section-status {{ $responseState }}" aria-label="{{ $responseState === 'valid' ? 'A response is configured' : ($responseState === 'attention' ? 'A response is needed' : 'Response not yet reviewed') }}">{{ $stateSymbol($responseState) }}</i></a>
            </li>
        </ol>
    </nav>

    <div class="editor-grid">
        <div class="editor-main">
            <section id="request-definition" class="card form-card editor-section">
                <div class="card-heading">
                    <div>
                        <h2>Request</h2>
                        <p>Paste one curl command. MockDeck parses the text but never executes it.</p>
                    </div>
                </div>

                <div class="field">
                    <label for="endpoint-name">Name <span>optional</span></label>
                    <input id="endpoint-name" type="text" wire:model="name" placeholder="{{ $derivedName }}" aria-describedby="endpoint-name-help @error('name') endpoint-name-error @enderror">
                    <small id="endpoint-name-help" class="field-help">Leave blank to save this endpoint as “{{ $derivedName }}”.</small>
                    @error('name') <p id="endpoint-name-error" class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="endpoint-control-grid">
                    <div class="control-card">
                        <div>
                            <strong>Endpoint enabled</strong>
                            <small>Disabled endpoints remain configured but never match.</small>
                        </div>
                        <label class="switch-control">
                            <input type="checkbox" wire:model="enabled" aria-label="Endpoint enabled">
                            <span aria-hidden="true"></span>
                        </label>
                    </div>
                    <div class="field control-field">
                        <label for="endpoint-priority">
                            Priority <span>-1000 to 1000</span>
                            <x-help-tip title="Priority" label="Priority is considered before signature specificity. For example, priority 10 can win over priority 0 even when priority 0 includes more headers." />
                        </label>
                        <input id="endpoint-priority" type="number" min="-1000" max="1000" step="1" wire:model="priority" aria-describedby="priority-help @error('priority') priority-error @enderror">
                        <small id="priority-help" class="field-help">Use 0 normally. Increase it only to prefer this endpoint over an overlapping signature.</small>
                        @error('priority') <p id="priority-error" class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="field curl-field">
                    <div class="label-row curl-label-row">
                        <label for="raw-curl">Curl command</label>
                        <div class="input-actions">
                            <button class="text-button" type="button" data-paste-curl>Paste</button>
                            <button class="text-button" type="button" data-toggle-wrap>Wrap: on</button>
                            <button class="text-button" type="button" wire:click="loadExample">Load example</button>
                            <button class="text-button danger-text" type="button" wire:click="clearCurl" @disabled($rawCurl === '')>Clear</button>
                        </div>
                    </div>
                    <textarea
                        id="raw-curl"
                        class="code-input curl-editor"
                        rows="8"
                        spellcheck="false"
                        data-curl-editor
                        data-auto-grow
                        wire:model.live.debounce.300ms="rawCurl"
                        aria-describedby="curl-status @error('rawCurl') raw-curl-error @enderror"
                        placeholder="curl --request GET 'https://api.example.test/v1/items?limit=10'"
                    ></textarea>
                    @error('rawCurl') <p id="raw-curl-error" class="field-error" role="alert">{{ $message }}</p> @enderror

                    <div id="curl-status" class="parse-status {{ $preview ? 'success' : ($previewError ? 'error' : 'idle') }}" aria-live="polite">
                        @if ($preview)
                            <span aria-hidden="true">✓</span>
                            <strong>Parsed:</strong> {{ $preview['parsed']->method }} · {{ count($preview['parsed']->headers) }} {{ Str::plural('header', count($preview['parsed']->headers)) }} · body {{ strlen($preview['parsed']->body) }} B
                        @elseif ($previewError)
                            <span aria-hidden="true">!</span>
                            <strong>Could not parse:</strong> {{ $previewError }}
                        @else
                            <span aria-hidden="true">i</span> Paste one curl command to preview its signature.
                        @endif
                        <span wire:loading.delay wire:target="rawCurl" class="loading-label">Parsing…</span>
                    </div>

                    @if ($isExample)
                        <div class="validation-panel warning-panel" role="alert">
                            <strong>This is an example, not a real endpoint.</strong>
                            Replace the URL and values before saving.
                        </div>
                    @endif

                    @if ($curlWarnings !== [])
                        <div class="validation-panel warning-panel">
                            <strong>Review curl behavior</strong>
                            <ul>@foreach ($curlWarnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                        </div>
                    @endif

                    @if ($containsSecrets)
                        <div class="secret-warning">
                            <span aria-hidden="true">!</span>
                            <div><strong>Possible credentials detected</strong><p>Mask tokens, API keys, cookies, and authentication values before sharing this configuration.</p></div>
                            <button class="button button-secondary button-small" type="button" wire:click="maskSecrets">Mask detected secrets</button>
                        </div>
                    @endif

                    @if ($duplicate)
                        <div class="validation-panel error-panel" role="alert">
                            <strong>Duplicate signature</strong>
                            This request matches <a class="text-link" href="{{ route('dashboard.endpoints.edit', $duplicate) }}" wire:navigate>{{ $duplicate->displayName() }}</a>. Exact duplicate signatures are blocked to keep matching deterministic.
                        </div>
                    @endif
                </div>
            </section>

            <section id="matching-policy" class="card form-card editor-section">
                <div class="card-heading">
                    <div>
                        <h2>Matching</h2>
                        <p>Control which request data participates in the canonical signature.</p>
                    </div>
                </div>

                <div class="policy-group">
                    <div class="policy-heading">
                        <div><strong>Headers</strong><span>{{ count($headerAnalysis) }} parsed</span></div>
                        <x-help-tip title="Header matching" label="Volatile transport headers are always removed. You can additionally ignore cookies, authentication, or every header." />
                    </div>
                    <label class="toggle-row {{ $excludeHeaders ? 'overridden' : '' }}">
                        <span><strong>Ignore cookies</strong><small>Removes the Cookie header from the signature.</small></span>
                        <input type="checkbox" wire:model.live="excludeCookies" @disabled($excludeHeaders) @checked($excludeHeaders || $excludeCookies)>
                    </label>
                    <label class="toggle-row {{ $excludeHeaders ? 'overridden' : '' }}">
                        <span><strong>Ignore authentication</strong><small>Removes Authorization and Proxy-Authorization headers.</small></span>
                        <input type="checkbox" wire:model.live="excludeAuth" @disabled($excludeHeaders) @checked($excludeHeaders || $excludeAuth)>
                    </label>
                    <label class="toggle-row">
                        <span><strong>Ignore all headers</strong><small>Overrides both choices above. Method, path/query, and body still match.</small></span>
                        <input type="checkbox" wire:model.live="excludeHeaders">
                    </label>
                    @if ($excludeHeaders)
                        <p class="override-note"><span aria-hidden="true">i</span> Cookie and authentication exclusions are included because all headers are ignored.</p>
                    @endif

                    @if ($headerAnalysis !== [])
                        <div class="parsed-policy-list">
                            @foreach ($headerAnalysis as $header)
                                <div class="{{ $header['excluded_reason'] ? 'excluded' : '' }}">
                                    <code>{{ $header['name'] }}</code>
                                    <span>{{ $header['excluded_reason'] ?: 'included in signature' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="policy-group read-only-policy">
                    <div class="policy-heading"><div><strong>Query</strong><span>{{ count($queryParameters) }} parsed</span></div><span class="included-label">Always included</span></div>
                    @if ($queryParameters !== [])
                        <div class="parsed-policy-list">
                            @foreach ($queryParameters as $parameter)
                                <div><code>{{ $parameter['key'] }}</code><span>{{ Str::limit($parameter['display_value'], 80) }}</span></div>
                            @endforeach
                        </div>
                    @else
                        <p>No query parameters were parsed.</p>
                    @endif
                </div>

                <div class="policy-group read-only-policy">
                    <div class="policy-heading"><div><strong>Body</strong><span>{{ $preview ? strlen($preview['parsed']->body) : 0 }} B</span></div><span class="included-label">Always included</span></div>
                    <p>JSON object keys are sorted before hashing; other bodies are trimmed and matched as text.</p>
                </div>

                <div class="info-note policy-gap-note">
                    <strong>Field-level exclusions are not available</strong>
                    <p>The current signature model supports the three header policies above. Query parameters and individual headers are shown for review but cannot be excluded separately.</p>
                </div>
            </section>

        </div>

        <aside class="preview-column">
            <section class="card preview-card sticky-card">
                <div class="card-heading tight">
                    <div>
                        <h2>Signature</h2>
                        <p>The canonical request saved for matching.</p>
                    </div>
                </div>

                @if ($preview)
                    <div class="request-summary">
                        <span class="method-badge method-{{ strtolower($preview['parsed']->method) }}">{{ $preview['parsed']->method }}</span>
                        <code>{{ $preview['parsed']->url }}</code>
                        <button class="copy-inline" type="button" data-copy-text="{{ $preview['parsed']->method.' '.$preview['parsed']->url }}" aria-label="Copy parsed method and URL">Copy</button>
                    </div>

                    <dl class="preview-facts">
                        <div><dt>Signature version <x-help-tip title="Signature version" label="V1 includes all headers; V2 ignores cookies; V3 ignores authentication; V4 ignores both; V5 ignores all headers." /></dt><dd>{{ $preview['variant']->name }}</dd></div>
                        <div><dt>Headers</dt><dd>{{ count($preview['parsed']->headers) }}</dd></div>
                        <div><dt>Body</dt><dd>{{ strlen($preview['parsed']->body) }} B</dd></div>
                    </dl>

                    <div class="code-block-wrap hash-block" wire:key="hash-{{ $preview['variant']->hash }}">
                        <div class="code-block-heading"><span>SHA-256</span><button type="button" data-copy-text="{{ $preview['variant']->hash }}">Copy</button></div>
                        <code class="hash-value">{{ implode(' ', str_split($preview['variant']->hash, 8)) }}</code>
                    </div>

                    @if (collect($headerAnalysis)->contains(fn ($header) => $header['excluded_reason'] !== null))
                        <div class="canonical-diff">
                            <strong>Removed by matching policy</strong>
                            @foreach ($headerAnalysis as $header)
                                @if ($header['excluded_reason'])
                                    <div><del>{{ $header['name'] }}: {{ $header['display_value'] }}</del><span>{{ $header['excluded_reason'] }}</span></div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <details class="normalized-details" open>
                        <summary><span class="details-chevron" aria-hidden="true">›</span> Canonical request</summary>
                        <pre>{{ $displayCanonical }}</pre>
                    </details>

                    <details class="normalized-details">
                        <summary><span class="details-chevron" aria-hidden="true">›</span> Parsed headers ({{ count($headerAnalysis) }})</summary>
                        <div class="header-table-wrap">
                            <table class="header-table">
                                <thead><tr><th scope="col">Header</th><th scope="col">Value</th></tr></thead>
                                <tbody>
                                    @forelse ($headerAnalysis as $header)
                                        <tr>
                                            <th scope="row">{{ $header['name'] }}</th>
                                            <td>
                                                @if ($header['sensitive'])
                                                    <button class="secret-value" type="button" data-secret-value="{{ $header['value'] }}" aria-label="Reveal {{ $header['name'] }} value">{{ $header['display_value'] }}</button>
                                                @else
                                                    <code>{{ $header['display_value'] }}</code>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="2">No headers</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <details class="normalized-details">
                        <summary><span class="details-chevron" aria-hidden="true">›</span> Parsed body ({{ strlen($preview['parsed']->body) }} B)</summary>
                        <pre>{{ $prettyBody !== '' ? $prettyBody : '— empty —' }}</pre>
                        <p class="details-note">JSON keys are sorted recursively during canonicalization; array order is preserved.</p>
                    </details>
                @else
                    <div class="preview-placeholder">
                        <span aria-hidden="true">#</span>
                        <p>A valid curl command will reveal its canonical request and digest here.</p>
                    </div>
                @endif
            </section>

            <div class="info-note dismissible-note" data-host-note>
                <button type="button" aria-label="Dismiss mock host information" data-dismiss-host-note>×</button>
                <strong>Mock hosts are interchangeable</strong>
                <p>Matching uses the URL path and query, not the scheme, host, or port.</p>
            </div>
        </aside>
    </div>

    <div id="save-actions" class="sticky-action-bar" data-sticky-action-bar>
        <div>
            <p id="endpoint-action-status" class="action-note">{{ $statusMessage }}</p>
        </div>
        <div>
            <a class="button button-secondary" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Cancel</a>
            <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="save" @disabled(! $requestReady) @if ($saveBlockReason !== '') aria-describedby="endpoint-action-status" title="{{ $saveBlockReason }}" @endif>
                <span wire:loading.remove wire:target="save">{{ $endpointId ? 'Save changes' : 'Create endpoint' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>
</form>
