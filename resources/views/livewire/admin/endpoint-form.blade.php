<div class="endpoint-editor" data-unsaved-form @if ($endpointId) data-global-save-editor @endif data-save-attempt="{{ $saveAttempt['id'] ?? '' }}" data-save-pending="{{ $savingAll ? 'true' : 'false' }}" data-server-dirty="{{ $saveSummary === \App\Livewire\Admin\EndpointForm::UNCONFIRMED ? 'true' : 'false' }}" data-draft-scope="request" data-draft-baseline="{{ json_encode($requestBaseline) }}" data-draft-current="{{ json_encode($this->requestDraftValues()) }}" x-data="{ activeTab: $wire.entangle('activeTab'), get workspaceTab() { return ['callback', 'settings'].includes(this.activeTab) ? this.activeTab : 'editor'; }, switchTab(tab) { this.activeTab = tab === 'editor' ? 'request' : tab; $wire.touchSection(this.activeTab); } }">
    <header class="page-header">
        <div class="page-header-copy">
            <h1 class="page-breadcrumbs"><span class="editor-breadcrumb"><a href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Endpoints</a><span aria-hidden="true">/</span><span>{{ $endpoint?->collection?->name ?? 'Unfiled' }}</span></span><x-inline-title :name="$name" :placeholder="$preview ? $derivedName : 'New endpoint'" /></h1>
            @error('name') <p class="field-error" data-error-for="{{ 'name' }}" role="alert">{{ $message }}</p> @enderror
        </div>
        @if ($endpointId)<div class="page-header-actions"><div class="endpoint-invocation-actions">
                <button class="button button-secondary button-small" type="button" data-copy-curl data-copy-endpoint="{{ $endpointId }}" title="Copies the saved request using today's active Environment values; unsaved changes are not included."><x-workbench-icon name="copy" /><span class="invocation-label">Copy mock curl</span></button>
                <a class="button button-tertiary button-small" href="{{ route('dashboard.logs.index', ['endpoint' => $endpointId]) }}" wire:navigate title="Logs"><x-workbench-icon name="logs" /><span class="invocation-label">Logs</span></a>
            </div><button class="icon-button" type="button" data-dialog-open="endpoint-history-dialog" aria-label="Endpoint history" title="Endpoint history"><svg class="workbench-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 11a9 9 0 1 1 2 7M3 5v6h6m3-5v7l4 2" /></svg><span class="invocation-label">History</span></button></div>@endif
    </header>
    @php
        $requestReady = (bool) $preview && ! $duplicate && ! $isExample && $this->requestMetadataReady();
        $matchingReady = (bool) $preview;
        $responseReady = $this->responseSectionReady();
        $hasNoResponses = $endpoint && ! $endpoint->responses()->exists();
        $saveBlockReason = ! $preview
            ? 'Paste a valid curl command to continue.'
            : ($duplicate
                ? 'Resolve the duplicate signature before saving.'
                : ($isExample ? 'Replace the example curl before saving.' : (! $this->requestMetadataReady() ? 'Correct the request settings before saving.' : '')));
        $requestState = $requestReady ? 'valid' : (($submitAttempted || in_array('request', $touchedSections, true)) ? 'attention' : 'neutral');
        $matchingState = $matchingReady ? 'valid' : (($submitAttempted || in_array('matching', $touchedSections, true)) ? 'attention' : 'neutral');
        $responseState = $responseReady ? 'valid' : (($endpointId || $submitAttempted || in_array('response', $touchedSections, true)) ? 'attention' : 'neutral');
        $callbackReady = $draftCallbackReady ?? ($endpoint && $endpoint->responses()->exists());
        $callbackState = $callbackReady ? 'valid' : (($endpointId || in_array('callback', $touchedSections, true)) ? 'attention' : 'neutral');
        $stateSymbol = static fn (string $state): string => $state === 'valid' ? '✓' : ($state === 'attention' ? '!' : '');
        $statusMessage = $saveBlockReason !== ''
            ? $saveBlockReason
            : (! $responseReady
                ? 'Next: complete response selection so this endpoint can answer requests.'
                : 'Editing '.$derivedName.' · Ctrl/⌘ + Enter saves.');
        $settingsState = $this->requestMetadataReady() ? 'valid' : (($submitAttempted || in_array('settings', $touchedSections, true)) ? 'attention' : 'neutral');
    @endphp

    <nav class="flow-nav endpoint-workspace-tabs" aria-label="Endpoint workspace">
        <div role="tablist" aria-label="Endpoint workspace" data-endpoint-tabs>
            @foreach (['editor' => ['Editor', $requestState, 'panel-editor panel-response'], 'callback' => ['Callback', $callbackState, 'panel-callback'], 'settings' => ['Settings', $settingsState, 'panel-settings']] as $tab => [$label, $state, $panel])
                <button id="tab-{{ $tab }}" type="button" role="tab" aria-controls="{{ $panel }}"
                        x-bind:aria-selected="workspaceTab === '{{ $tab }}'" x-bind:tabindex="workspaceTab === '{{ $tab }}' ? 0 : -1" x-on:click="switchTab('{{ $tab }}')">
                    @if ($tab === 'editor')<svg class="workbench-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 16" /></svg>@endif<span>{{ $label }}</span><i class="section-status {{ $state }}" aria-label="{{ $state === 'valid' ? $label.' settings are ready' : ($state === 'attention' ? $label.' needs attention' : $label.' not yet reviewed') }}">{{ $stateSymbol($state) }}</i>
                </button>
            @endforeach
        </div>

    </nav>

    @if ($preview && ! $isExample && ! $curlExpanded)
    <div class="endpoint-request-toolbar" x-show="workspaceTab === 'editor'">
                        <div class="curl-summary">
                            <span class="method-badge method-{{ strtolower($preview['parsed']->method) }}">{{ $preview['parsed']->method }}</span>
                            <code title="{{ $preview['parsed']->url }}">{{ $preview['parsed']->url }}</code>
                            <button class="button button-tertiary button-small" type="button" wire:click="$set('curlExpanded', true)"><svg class="workbench-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m4 16 12-12 4 4L8 20H4Zm10-10 4 4" /></svg><span class="request-source-label">Edit request</span></button>
                        </div>
    </div>
    @endif

    <div class="endpoint-panel-viewport endpoint-workspace-viewport" data-editor-viewport>
        <div class="validation-panel error-panel" role="status" aria-live="polite" aria-atomic="true" data-save-result @if ($saveSummary === '') hidden @endif>
            <strong>{{ $saveSummary }}</strong>
            <dl class="definition-list">
                @foreach ($this->visibleSaveScopes() as $key => $scope)
                    <div data-save-scope="{{ $key }}" data-save-owner="{{ $scope['owner'] }}" data-save-fields="{{ json_encode($scope['fields'] ?? []) }}">
                        <dt>{{ $scope['label'] }}</dt><dd>{{ $scope['state'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    <div class="endpoint-authoring-grid" x-bind:class="{ 'is-single-pane': workspaceTab !== 'editor' }">
    <form id="endpoint-settings" class="endpoint-request-workspace" wire:submit="saveAll" data-endpoint-save-form x-show="workspaceTab !== 'callback'">
        <div id="panel-editor" role="tabpanel" aria-labelledby="tab-editor" x-show="workspaceTab === 'editor'">
            <div class="editor-pane-heading"><h2>Request</h2>                    <div id="curl-status" class="parse-status {{ $preview ? 'success' : ($previewError ? 'error' : 'idle') }}" aria-live="polite">
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
                    </div></div>
            <div class="editor-request-tabs" role="tablist" aria-label="Request configuration" data-editor-subtabs>
                @foreach (['request' => ['Request', $requestState], 'matching' => ['Matching', $matchingState]] as $tab => [$label, $state])
                    <button id="tab-{{ $tab }}" type="button" role="tab" aria-controls="panel-{{ $tab }}" x-bind:aria-selected="(activeTab === 'matching' ? 'matching' : 'request') === '{{ $tab }}'" x-bind:tabindex="(activeTab === 'matching' ? 'matching' : 'request') === '{{ $tab }}' ? 0 : -1" x-on:click="switchTab('{{ $tab }}')">
                        <span>{{ $label }}</span><i class="section-status {{ $state }}" aria-label="{{ $state === 'valid' ? ($tab === 'request' ? 'Request is valid' : 'Matching policy is ready') : ($state === 'attention' ? ($tab === 'request' ? 'Request needs attention' : 'Matching policy needs attention') : $label.' not yet reviewed') }}">{{ $stateSymbol($state) }}</i>
                    </button>
                @endforeach
            </div>
            <section id="panel-request" role="tabpanel" aria-labelledby="tab-request" class="card form-card editor-section" x-show="activeTab !== 'matching'">
                <div class="card-heading">
                    <div>
                        <h2>Request</h2>
                        <p>Paste one curl command. MockDeck parses the text but never executes it.</p>
                    </div>
                </div>

                @if ($preview && ! $isExample && ! $curlExpanded)
                    <div class="request-header-preview">
                        @if ($excludeHeaders || $headerAnalysis === [])
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 6h16M3 12h12M3 18h9m4-3 3 3-3 3" /></svg>
                            <div><strong>{{ $headerAnalysis === [] ? 'No request headers' : 'Headers excluded from matching' }}</strong><p>{{ $excludeHeaders ? 'Headers are currently excluded from matching.' : 'No headers were parsed from this request.' }}</p></div>
                        @else
                            <dl>@foreach ($headerAnalysis as $header)<div><dt>{{ $header['name'] }}</dt><dd><code>{{ $header['display_value'] }}</code></dd></div>@endforeach</dl>
                        @endif
                    </div>
                    @endif

                <div class="field curl-field">
                    @if (! $preview || $isExample || $curlExpanded)
                    <div class="label-row curl-label-row">
                        <label for="raw-curl">Curl command</label>
                        <div class="input-actions">
                            <button class="text-button" type="button" data-paste-curl>Paste</button>
                            <button class="text-button" type="button" data-toggle-wrap>Wrap: on</button>
                            <button class="text-button" type="button" wire:click="loadExample">Load example</button>
                            <button class="text-button danger-text" type="button" wire:click="clearCurl" @disabled($rawCurl === '')>Clear</button>
                            @if ($preview && ! $isExample)<button class="text-button" type="button" wire:click="$set('curlExpanded', false)">Done editing</button>@endif
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
                    @endif
                    @error('rawCurl') <p id="raw-curl-error" class="field-error" data-error-for="{{ 'rawCurl' }}" role="alert">{{ $message }}</p> @enderror



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
            <section id="panel-matching" role="tabpanel" aria-labelledby="tab-matching" class="card form-card editor-section" x-show="activeTab === 'matching'" x-cloak>
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
                    @if ($headerAnalysis !== [])
                        <details class="normalized-details" data-disclosure wire:ignore.self wire:key="matching-header-details">
                            <summary><x-disclosure-chevron /> Header matching details ({{ count($headerAnalysis) }})</summary>
                        <div class="parsed-policy-list">
                            @foreach ($headerAnalysis as $header)
                                <div class="{{ $header['excluded_reason'] ? 'excluded' : '' }}">
                                    <code>@if($header['excluded_reason'])<del>{{ $header['name'] }}</del>@else{{ $header['name'] }}@endif</code>
                                    <span>{{ $header['excluded_reason'] ?: 'included in signature' }} @if($header['excluded_reason'] && $header['variable_tokens'] !== [])<small>{{ implode(', ', $header['variable_tokens']) }}</small>@endif@if($header['coarse_excluded'] && in_array($header['normalized_name'], $excludedHeaderNames, true)) · individual exclusion retained (redundant)@endif</span>
                                    <label class="toggle-row field-exclusion-toggle {{ $header['coarse_excluded'] ? 'overridden' : '' }}">
                                        <span>Exclude</span><input type="checkbox" wire:model.live="excludedHeaderNames" value="{{ $header['normalized_name'] }}" aria-label="Exclude header {{ $header['name'] }}" @disabled($header['coarse_excluded'])>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        </details>
                    @endif
                </div>

                <div class="policy-group">
                    <div class="policy-heading"><div><strong>Query</strong><span>{{ count($queryParameters) }} parsed</span></div><x-badge>{{ $excludedQueryParams === [] ? 'Always included' : collect($queryParameters)->where('excluded', true)->count().' of '.count($queryParameters).' excluded' }}</x-badge></div>
                    @if ($queryParameters !== [])
                        <div class="parsed-policy-list">
                            @foreach ($queryParameters as $parameter)
                                <div class="{{ $parameter['excluded'] ? 'excluded' : '' }}">
                                    <code>@if($parameter['excluded'])<del>{{ $parameter['key'] }}</del>@else{{ $parameter['key'] }}@endif</code><span>{{ Str::limit($parameter['display_value'], 80) }} @if($parameter['excluded'] && $parameter['variable_tokens'] !== [])<small>{{ implode(', ', $parameter['variable_tokens']) }}</small>@endif</span>
                                    <label class="toggle-row field-exclusion-toggle"><span>Exclude</span><input type="checkbox" wire:model.live="excludedQueryParams" value="{{ $parameter['key'] }}" aria-label="Exclude query {{ $parameter['key'] }}"></label>
                                </div>
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

                @if ($showExclusionHint)
                    <div class="info-note exclusion-hint" role="status">
                        <p>Toggle Exclude next to any query parameter or header to leave it out of the signature.</p>
                        <button type="button" class="text-button" wire:click="dismissExclusionHint" aria-label="Dismiss field exclusion hint">Dismiss</button>
                    </div>
                @endif
                @error('excludedQueryParams') <p class="field-error" data-error-for="{{ 'excludedQueryParams' }}" role="alert">{{ $message }}</p> @enderror
                @error('excludedHeaderNames') <p class="field-error" data-error-for="{{ 'excludedHeaderNames' }}" role="alert">{{ $message }}</p> @enderror
            </section><section class="matching-overview"><div class="editor-pane-heading"><h3>Matching rules</h3>@if ($preview)<span class="ui-badge">Signature {{ $preview['variant']->name }}</span>@endif</div><div class="matching-overview-controls">
                    <label class="toggle-row">
                        <span><strong>Ignore all headers</strong><small>Overrides both choices above. Method, path/query, and body still match.</small></span>
                        <input type="checkbox" wire:model.live="excludeHeaders">
                    </label>
                    <div class="toggle-row {{ $excludeHeaders ? 'overridden' : '' }}">
                        <span><span class="label-row"><label for="ignore-cookies"><strong>Ignore cookies</strong></label><span wire:ignore><x-help-tip title="Browser cookies and matching" label="A request carrying a Cookie header does not match an endpoint saved without one unless cookies are ignored. Enable Ignore cookies to match browser traffic that carries cookies." /></span></span><small id="ignore-cookies-description">Removes the Cookie header from the signature.</small></span>
                        <input id="ignore-cookies" type="checkbox" aria-describedby="ignore-cookies-description" wire:model.live="excludeCookies" @disabled($excludeHeaders) @checked($excludeHeaders || $excludeCookies)>
                    </div>

                    <label class="toggle-row {{ $excludeHeaders ? 'overridden' : '' }}">
                        <span><strong>Ignore authentication</strong><small>Removes Authorization and Proxy-Authorization headers.</small></span>
                        <input type="checkbox" wire:model.live="excludeAuth" @disabled($excludeHeaders) @checked($excludeHeaders || $excludeAuth)>
                    </label>
                <div class="policy-group path-pattern-policy">
                    <label class="toggle-row">
                        <span><strong>Use path parameters</strong><small>Wildcard segments; query, retained headers and body still match exactly.</small></span>
                        <input type="checkbox" wire:model.live="pathPatternEnabled" aria-label="Use path parameters">
                    </label>
                    @if ($pathPatternEnabled && $pathSegments !== [])
                        <div class="path-segment-editor" aria-label="Path segments">
                            @foreach ($pathSegments as $index => $segment)
                                @if ($index > 0)<span aria-hidden="true">/</span>@endif
                                @if ($segment !== '')
                                    @php($isParameter = str_starts_with($segment, '{') && str_ends_with($segment, '}'))
                                    <div class="path-segment-chip {{ $isParameter ? 'is-parameter' : '' }}" wire:key="path-segment-{{ $index }}">
                                        <button type="button" class="button button-secondary button-small" wire:click="togglePathSegment({{ $index }})" aria-pressed="{{ $isParameter ? 'true' : 'false' }}" aria-label="{{ $isParameter ? 'Use literal for segment '.$index : 'Use parameter for segment '.$index }}" title="{{ $isParameter ? 'Use literal segment' : 'Use path parameter' }}">{{ $isParameter ? '{ }' : $segment }}</button>
                                        @if ($isParameter)<input type="text" class="path-parameter-name" wire:model.live.debounce.300ms="pathParameterNames.{{ $index }}" aria-label="Parameter name for segment {{ $index }}" maxlength="80" spellcheck="false">@endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @error('pathPatternEnabled') <p class="field-error" data-error-for="{{ 'pathPatternEnabled' }}" role="alert">{{ $message }}</p> @enderror
                </div>


</div>
                    @if ($excludeHeaders)
                        <p class="override-note" title="Cookie and authentication exclusions are included because all headers are ignored."><span aria-hidden="true">i</span> Cookie and authentication exclusions are included because all headers are ignored.</p>
                    @endif

</section>        <aside class="preview-column">
            <section class="card preview-card sticky-card">
                <div class="card-heading tight">
                    <div>
                        <h2>Signature</h2>
                        <p>Canonical preview of this draft; Save changes persists it.</p>
                    </div>
                </div>

                @if ($preview)
                    <details class="normalized-details" data-disclosure open>
                        <summary><x-disclosure-chevron /> Canonical request</summary>
                        <pre>{{ $displayCanonical }}</pre>
                    </details>
                    <p class="field-help">Source URL · not the mock invocation URL</p>
                    <div class="request-summary">
                        <span class="method-badge method-{{ strtolower($preview['parsed']->method) }}">{{ $preview['parsed']->method }}</span>
                        <code>{{ $preview['parsed']->url }}</code>
                        <button class="copy-inline" type="button" data-copy-text="{{ $preview['parsed']->method.' '.$preview['parsed']->url }}" aria-label="Copy parsed method and URL">Copy</button>
                    </div>

                    <dl class="preview-facts">
                        <div><dt>Signature version <x-help-tip title="Signature version" label="V1 includes all headers; V2 ignores cookies; V3 ignores authentication; V4 ignores both; V5 ignores all headers; V6 adds field exclusions or path parameters." /></dt><dd>{{ $preview['variant']->name }}</dd></div>
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
                            @php($removedHeaders = collect($headerAnalysis)->filter(fn ($header) => $header['excluded_reason'] !== null))
                            @foreach ($removedHeaders->take(5) as $header)
                                    <div><del>{{ $header['name'] }}: {{ $header['display_value'] }}</del><span>{{ $header['excluded_reason'] }}</span></div>
                            @endforeach
                            @if ($removedHeaders->count() > 5)<p class="field-help">+{{ $removedHeaders->count() - 5 }} more in Header matching details</p>@endif
                        </div>
                    @endif



                    <details class="normalized-details" data-disclosure>
                        <summary><x-disclosure-chevron /> Parsed headers ({{ count($headerAnalysis) }})</summary>
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

                    <details class="normalized-details" data-disclosure>
                        <summary><x-disclosure-chevron /> Parsed body ({{ strlen($preview['parsed']->body) }} B)</summary>
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
        <section id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="endpoint-settings-workspace" x-show="workspaceTab === 'settings'" x-cloak>
            <div class="editor-pane-heading"><h2>Endpoint settings</h2><span class="field-help">Saved with Save changes</span></div>
            <p class="field-help">Rename with the title above. Organize this endpoint and control when it can match.</p>
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

                </div>

                <div class="organization-grid">
                    <div class="field">
                        <label for="endpoint-collection">Collection</label>
                        <select class="ui-select" id="endpoint-collection" wire:model="collectionId">
                            <option value="">No collection</option>
                            @foreach ($collections as $endpointCollection)<option value="{{ $endpointCollection->id }}">{{ $endpointCollection->name }}</option>@endforeach
                        </select>
                        <div class="inline-create-control">
                            <input type="text" wire:model="newCollectionName" placeholder="New collection name" aria-label="New collection name">
                            <button class="button button-secondary button-small" type="button" wire:click="createCollectionInline">Create</button>
                        </div>
                        @error('collectionId') <p class="field-error" data-error-for="{{ 'collectionId' }}">{{ $message }}</p> @enderror
                        @error('newCollectionName') <p class="field-error" data-error-for="{{ 'newCollectionName' }}">{{ $message }}</p> @enderror
                    </div>

                    <div class="field control-field">
                        <label for="endpoint-priority">
                            Priority <span>-1000 to 1000</span>
                            <x-help-tip title="Priority" label="Priority is considered before signature specificity. For example, priority 10 can win over priority 0 even when priority 0 includes more headers." />
                        </label>
                        <input id="endpoint-priority" type="number" min="-1000" max="1000" step="1" wire:model="priority" aria-describedby="priority-help @error('priority') priority-error @enderror">
                        <small id="priority-help" class="field-help">Use 0 normally. Increase it only to prefer this endpoint over an overlapping signature.</small>
                        @error('priority') <p id="priority-error" class="field-error" data-error-for="{{ 'priority' }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <fieldset class="field tag-input settings-tags">
                        <legend>Tags <x-help-tip title="Endpoint tags" label="Use tags to filter endpoints or assign them in bulk. Save changes to store this endpoint’s tag assignments." /></legend>
                        <div class="tag-choice-list">
                            @forelse ($availableTags as $tag)
                                <label class="tag-chip selectable {{ in_array((string) $tag->id, array_map('strval', $tagIds), true) ? 'selected' : '' }}">
                                    <input type="checkbox" wire:model="tagIds" value="{{ $tag->id }}"><span>{{ $tag->name }}</span>
                                </label>
                            @empty
                                <span class="field-help">No tags yet.</span>
                            @endforelse
                        </div>
                        <div class="inline-create-control">
                            <input type="text" wire:model="newTagName" placeholder="New tag" aria-label="New tag name">
                            <button class="button button-secondary button-small" type="button" wire:click="createTagInline">Add tag</button>
                        </div>
                        @error('tagIds.*') <p class="field-error" data-error-for="{{ 'tagIds.*' }}">{{ $message }}</p> @enderror
                        @error('newTagName') <p class="field-error" data-error-for="{{ 'newTagName' }}">{{ $message }}</p> @enderror
                    </fieldset>

                <details class="normalized-details environment-overrides" data-disclosure>
                    <summary><x-disclosure-chevron /> Environment availability</summary>
                    <div class="table-scroll">
                        <table class="header-table">
                            <thead><tr><th scope="col">Environment</th><th scope="col">Behavior</th></tr></thead>
                            <tbody>
                                <tr><th scope="row">All other environments</th><td>Inherits endpoint state ({{ $enabled ? 'enabled' : 'disabled' }})</td></tr>
                                @foreach ($environments as $environment)
                                    <tr>
                                        <th scope="row">{{ $environment->name }} @if($environment->is_default)<x-badge>Default</x-badge>@endif</th>
                                        <td>
                                            <select class="ui-select" wire:model="environmentOverrides.{{ $environment->id }}" aria-label="{{ $environment->name }} override">
                                                <option value="">Inherit endpoint state</option>
                                                <option value="1">Allow when endpoint is enabled</option>
                                                <option value="0">Force disabled</option>
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>



        </section>
    </form>
    <div class="endpoint-response-workspace" x-show="workspaceTab !== 'settings'">
        <div class="editor-pane-heading" x-show="workspaceTab === 'editor'">
            <h2 id="response-pane-heading" @if ($hasNoResponses) aria-describedby="response-empty-note" @endif>Responses</h2>
            <i data-response-state role="img" class="section-status {{ $responseState }}" aria-label="{{ $hasNoResponses ? 'No response yet' : ($responseReady ? 'Response selection is complete' : 'Response selection needs attention') }}">{{ $stateSymbol($responseState) }}</i>
        </div>
        @if ($endpoint)
            <livewire:admin.response-manager :endpoint="$endpoint" :key="'endpoint-responses-'.$endpointId" />
        @else
            <section id="panel-response" role="region" aria-labelledby="response-pane-heading" x-show="workspaceTab === 'editor'" class="empty-state editor-create-response"><h3>Create the endpoint first</h3><p>Save the request, then add responses.</p><button class="button button-secondary" type="button" x-on:click="switchTab('request')">Configure request</button></section>
            <section id="panel-callback" role="tabpanel" aria-labelledby="tab-callback" x-show="activeTab === 'callback'" class="empty-state" x-cloak><h3>Add a response first</h3><p>Callbacks belong to individual responses.</p><button class="button button-secondary" type="button" x-on:click="switchTab('response')">Configure responses</button></section>
        @endif
    </div>
    </div>
    </div>

    @if ($endpointId)
            <x-dialog id="endpoint-history-dialog" class="history-dialog" title="Endpoint history" close-label="Close endpoint history" wire:ignore.self>
                <livewire:admin.revision-history entity-type="endpoint" :entity-id="$endpointId" :key="'endpoint-history-'.$endpointId" />
            </x-dialog>
    @endif

    @teleport('body')
    <div id="save-actions" class="sticky-action-bar editor-save-actions" data-sticky-action-bar>
        <div>
            <p id="endpoint-action-status" class="action-note">{{ $saveSummary !== '' ? 'Needs attention' : $statusMessage }}</p>
        </div>
        <div>
            <a class="button button-secondary" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Cancel</a>
            <button class="button button-primary" type="submit" form="endpoint-settings" wire:loading.attr="disabled" wire:target="saveAll,finishSavingAll" @disabled(! $requestReady || $savingAll) @if ($saveBlockReason !== '') aria-describedby="endpoint-action-status" title="{{ $saveBlockReason }}" @endif>
                <span wire:loading.remove wire:target="saveAll,finishSavingAll">{{ $savingAll ? 'Saving…' : ($endpointId ? 'Save changes' : 'Create endpoint') }}</span>
                <span wire:loading wire:target="saveAll,finishSavingAll">Saving…</span>
                <kbd class="editor-save-shortcut" aria-hidden="true">⌘ ↵</kbd>
            </button>
        </div>
    </div>
    @endteleport
</div>
