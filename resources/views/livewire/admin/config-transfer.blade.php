<div>
    <div class="transfer-grid">
        <section class="card transfer-card" aria-labelledby="export-heading">
            <div class="card-heading tight">
                <div>
                    <h2 id="export-heading">Export configuration</h2>
                    <p>Download every endpoint or choose a portable subset.</p>
                </div>
            </div>

            <div class="export-scope-grid">
                <label class="field">
                    <span>Export scope</span>
                    <select wire:model.live="exportScope">
                        <option value="all">All endpoints</option>
                        <option value="collection">One collection</option>
                        <option value="environment">One environment</option>
                    </select>
                </label>
                @if ($exportScope === 'collection')
                    <label class="field"><span>Collection</span><select wire:model="exportCollectionId"><option value="">Choose collection</option>@foreach($collections as $collection)<option value="{{ $collection->id }}">{{ $collection->name }} ({{ $collection->endpoints_count }})</option>@endforeach</select></label>
                @elseif ($exportScope === 'environment')
                    <label class="field"><span>Environment</span><select wire:model="exportEnvironmentId"><option value="">Choose environment</option>@foreach($environments as $environment)<option value="{{ $environment->id }}">{{ $environment->name }}</option>@endforeach</select></label>
                @endif
            </div>
            @if ($exportScope === 'environment')
                <div class="info-note"><strong>Inherited endpoints are included</strong><p>An endpoint with no override for the selected environment is included. Only an explicit disabled override excludes it.</p></div>
            @endif

            <label class="search-field transfer-search">
                <span class="sr-only">Search endpoints to export</span>
                <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <input type="search" wire:model.live.debounce.250ms="exportSearch" placeholder="Search endpoints…">
            </label>

            <div class="selection-toolbar">
                <strong>{{ count($selectedEndpointUuids) }} of {{ $endpointTotal }} selected</strong>
                <span>
                    <button class="text-button" type="button" wire:click="selectAll">Select all</button>
                    <button class="text-button" type="button" wire:click="clearSelection" @disabled($selectedEndpointUuids === [])>Clear</button>
                </span>
            </div>

            <div class="config-endpoint-list" aria-label="Endpoints available to export" wire:loading.class="is-loading" wire:target="exportSearch">
                @forelse ($endpoints as $endpoint)
                    <label class="config-endpoint-option" wire:key="export-endpoint-{{ $endpoint->uuid }}">
                        <input type="checkbox" value="{{ $endpoint->uuid }}" wire:model.live="selectedEndpointUuids">
                        <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
                        <span>
                            <strong>{{ $endpoint->displayName() }}</strong>
                            <small>{{ $endpoint->requestTarget() }} · {{ $endpoint->responses_count }} {{ Str::plural('response', $endpoint->responses_count) }}</small>
                        </span>
                    </label>
                @empty
                    <div class="mini-empty-state">
                        <strong>{{ $exportSearch === '' ? 'No endpoints available' : 'No endpoints match this search' }}</strong>
                        @if ($exportSearch !== '')
                            <button class="text-button" type="button" wire:click="$set('exportSearch', '')">Clear search</button>
                        @else
                            <a class="text-link" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>Create an endpoint</a>
                        @endif
                    </div>
                @endforelse
            </div>
            @error('selectedEndpointUuids') <p class="field-error">{{ $message }}</p> @enderror

            <label class="toggle-row transfer-toggle">
                <span>
                    <strong>Redact request secrets</strong>
                    <small>Replaces authentication, cookies, API-key headers, and sensitive query values. Endpoints with replaced secrets are exported disabled and remain disabled after import.</small>
                </span>
                <input type="checkbox" wire:model.live="redactSecrets">
            </label>

            @if ($redactSecrets)
                <button class="text-button disclosure-button {{ $showRedactionPreview ? 'is-open' : '' }}" type="button" wire:click="$toggle('showRedactionPreview')" aria-expanded="{{ $showRedactionPreview ? 'true' : 'false' }}" aria-controls="redaction-preview">
                    <span class="details-chevron" aria-hidden="true">›</span> Preview redactions
                </button>
                @if ($showRedactionPreview)
                    <div id="redaction-preview" class="redaction-preview" aria-live="polite">
                        <strong>Values replaced with <code>REDACTED</code></strong>
                        <ul>
                            <li>Authorization, cookies, API keys, and configured sensitive headers</li>
                            <li>Token, API-key, and other configured sensitive query parameters</li>
                        </ul>
                    </div>
                @endif
            @else
                <div class="validation-panel error-panel" role="alert">
                    <strong>File will contain secrets – store it securely.</strong>
                    Unredacted request credentials remain readable in the JSON file.
                </div>
                <label class="confirmation-row">
                    <input type="checkbox" wire:model="confirmSensitiveExport">
                    <span>I understand this export may contain credentials.</span>
                </label>
            @endif
            @error('redactSecrets') <p class="field-error">{{ $message }}</p> @enderror
            @error('export') <p class="field-error">{{ $message }}</p> @enderror

            <div class="export-facts">
                <div><strong>Included</strong><span>Endpoints, responses, collections, tags, environments, and non-secret variables</span></div>
                <div><strong>Not included</strong><span>Request logs and application credentials</span></div>
                <div><strong>Filename</strong><code>mockdeck-export-{{ now()->utc()->format('Ymd') }}.json</code></div>
            </div>

            <div class="form-actions transfer-actions">
                <span class="disabled-tooltip" title="{{ $selectedEndpointUuids === [] ? 'Select at least one endpoint to enable this export.' : '' }}">
                    <button class="button button-secondary" type="button" wire:click="exportSelected" wire:loading.attr="disabled" @disabled($selectedEndpointUuids === [])>
                        Export {{ count($selectedEndpointUuids) }} selected
                    </button>
                </span>
                <button class="button button-primary" type="button" wire:click="exportAll" wire:loading.attr="disabled" @disabled($endpointTotal === 0 || ($exportScope === 'collection' && $exportCollectionId === '') || ($exportScope === 'environment' && $exportEnvironmentId === '')) title="{{ $endpointTotal === 0 ? 'Create an endpoint before exporting.' : '' }}">
                    Export all
                </button>
            </div>
        </section>

        <section class="card transfer-card" aria-labelledby="import-heading">
            <div class="card-heading tight">
                <div>
                    <h2 id="import-heading">Import configuration</h2>
                    <p>Validate the file and preview every change before writing.</p>
                </div>
            </div>

            <form wire:submit="preview">
                <div class="field">
                    <label for="config-file">MockDeck JSON file</label>
                    <label class="file-dropzone {{ $configFile ? 'has-file' : '' }}" data-dropzone for="config-file">
                        <input class="sr-only" id="config-file" type="file" wire:model="configFile" accept=".json,application/json,application/vnd.mockdeck.config+json">
                        <span class="dropzone-icon" aria-hidden="true">⇧</span>
                        <strong>{{ $configFile ? 'File ready to validate' : 'Drop a JSON file here' }}</strong>
                        <span>{{ $configFile ? 'Choose another file if needed' : 'or browse from your device' }}</span>
                        <em>JSON only · maximum {{ number_format(config('mock.portable_config.max_bytes') / 1048576, 1) }} MB</em>
                    </label>
                    <div class="upload-progress" wire:loading.flex wire:target="configFile"><span class="spinner" aria-hidden="true"></span> Reading file…</div>
                    @if ($configFile)
                        <div class="selected-file">
                            <span aria-hidden="true">{ }</span>
                            <div><strong>{{ $configFile->getClientOriginalName() }}</strong><small>{{ number_format($configFile->getSize() / 1024, 1) }} KB</small></div>
                            <button class="icon-button" type="button" wire:click="removeFile" aria-label="Remove selected import file">Remove</button>
                        </div>
                    @endif
                    @error('configFile') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <fieldset class="field option-group import-modes" role="radiogroup" aria-labelledby="import-mode-legend">
                    <legend id="import-mode-legend">Import mode</legend>
                    @foreach ($modes as $importMode)
                        <label class="radio-option">
                            <input type="radio" name="import-mode" value="{{ $importMode->value }}" @checked($mode === $importMode->value) wire:model.live="mode">
                            <span>
                                <strong>{{ $importMode->label() }}</strong>
                                @if ($importMode->value === 'create-only')
                                    <span class="safe-badge">Safe default</span>
                                    <small>Stops without writing when an endpoint UUID already exists.</small>
                                @elseif ($importMode->value === 'upsert')
                                    <span class="overwrite-badge"><span aria-hidden="true">!</span> Overwrites matching endpoints &amp; responses</span>
                                    <small>Updates records that have the same portable UUID.</small>
                                @else
                                    <small>Generates new UUIDs so imported records remain separate.</small>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ($mode === 'upsert')
                    <label class="confirmation-row">
                        <input type="checkbox" wire:model.live="replaceResponses">
                        <span><strong>Replace response pools</strong><br>Delete local responses omitted from updated endpoints.</span>
                    </label>
                @endif

                <span class="disabled-tooltip button-full" title="{{ ! $configFile ? 'Choose a valid JSON file before previewing the import.' : ($errors->has('configFile') ? 'Resolve the file validation error first.' : '') }}">
                    <button class="button button-secondary button-full" type="submit" wire:loading.attr="disabled" @disabled(! $configFile || $errors->has('configFile'))>
                        <span wire:loading.remove wire:target="preview,configFile">Preview import</span>
                        <span wire:loading wire:target="preview,configFile">Checking configuration…</span>
                    </button>
                </span>
            </form>
        </section>
    </div>

    <section class="card import-preview preview-reserved" aria-labelledby="preview-heading" aria-live="polite">
        <div class="section-heading">
            <div>
                <h2 id="preview-heading">Preview</h2>
                <p>No database changes are made until you confirm the import.</p>
            </div>
        </div>

        @if ($plan === [])
            <div class="preview-empty">
                <span aria-hidden="true">i</span>
                <p><strong>No preview yet</strong> — choose a file and select Preview import.</p>
            </div>
        @else
            @php
                $changes = ($plan['counts']['creates'] ?? 0) + ($plan['counts']['updates'] ?? 0);
                $skipped = count(array_filter($plan['items'], fn ($item) => ($item['action'] ?? null) === 'skip'));
            @endphp
            <dl class="summary-grid import-summary-grid">
                <div><dt>New</dt><dd>{{ $plan['counts']['creates'] ?? 0 }}</dd></div>
                <div><dt>Updated</dt><dd>{{ $plan['counts']['updates'] ?? 0 }}</dd></div>
                <div><dt>Skipped</dt><dd>{{ $skipped }}</dd></div>
                <div><dt>Conflicts</dt><dd>{{ $plan['counts']['conflicts'] ?? 0 }}</dd></div>
                <div><dt>Invalid</dt><dd>{{ count($plan['errors'] ?? []) }}</dd></div>
            </dl>

            @if (($plan['counts']['revision_snapshots'] ?? 0) > 0)
                <div class="info-note revision-preview-note">
                    <strong>{{ $plan['counts']['revision_snapshots'] }} endpoints/responses will get a version snapshot before this update.</strong>
                    <p>These snapshots share one import batch, so every recorded update can be undone together.</p>
                </div>
            @endif

            @if ($plan['errors'] !== [])
                <div class="validation-panel error-panel" role="alert">
                    <strong>Resolve these errors before importing</strong>
                    <ul>@foreach ($plan['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
                    @if ($mode === 'create-only' && ($plan['counts']['conflicts'] ?? 0) > 0)
                        <button class="button button-secondary button-small" type="button" wire:click="switchToClone">Switch to Clone</button>
                    @endif
                </div>
            @endif

            @if ($plan['warnings'] !== [])
                <div class="validation-panel warning-panel">
                    <strong>Review these warnings</strong>
                    <ul>@foreach ($plan['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($plan['items'] !== [])
                <details class="import-items" open>
                    <summary>Review {{ count($plan['items']) }} {{ Str::plural('item', count($plan['items'])) }}</summary>
                    <div class="table-scroll">
                        <table class="log-table import-table">
                            <thead><tr><th scope="col">Endpoint</th><th scope="col">Action</th><th scope="col">Signature</th><th scope="col">Reason</th></tr></thead>
                            <tbody>
                                @foreach ($plan['items'] as $item)
                                    <tr>
                                        <td><strong>{{ $item['name'] ?: ($item['method'] ?? 'Endpoint').' '.($item['path'] ?? '') }}</strong><br><code>{{ $item['uuid'] }}</code></td>
                                        <td><span class="action-chip {{ $item['action'] }}">{{ ucfirst($item['action']) }}</span></td>
                                        <td>{{ $item['variant'] ?? '—' }}</td>
                                        <td>{{ $item['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{-- TODO: Render field-level update diffs when ImportPlan exposes old/new values. --}}
                </details>
            @endif

            @if ($plan['warnings'] !== [] && $plan['can_apply'])
                <label class="confirmation-row preview-confirmation">
                    <input type="checkbox" wire:model.live="acknowledgeWarnings">
                    <span>I reviewed the warnings and want to continue.</span>
                </label>
            @endif

            @error('import') <p class="field-error" role="alert">{{ $message }}</p> @enderror

            <div class="form-actions preview-actions">
                <button class="text-link" type="button" wire:click="exportAll">Export a backup first</button>
                <span class="disabled-tooltip" title="{{ ! $plan['can_apply'] ? 'Resolve preview errors before importing.' : (($plan['warnings'] !== [] && ! $acknowledgeWarnings) ? 'Review and acknowledge the warnings first.' : '') }}">
                    <button
                        class="button button-primary"
                        type="button"
                        wire:click="apply"
                        wire:loading.attr="disabled"
                        @disabled(! $plan['can_apply'] || ($plan['warnings'] !== [] && ! $acknowledgeWarnings))
                    >
                        <span wire:loading.remove wire:target="apply">Confirm import ({{ $changes }} {{ Str::plural('change', $changes) }})</span>
                        <span wire:loading wire:target="apply">Importing atomically…</span>
                    </button>
                </span>
            </div>
        @endif
    </section>

    @if ($summary !== [])
        <section class="card import-success" aria-live="polite">
            <span class="success-mark" aria-hidden="true">✓</span>
            <div>
                <h2>Import applied atomically</h2>
                <p>{{ $summary['endpoints_created'] }} endpoints created, {{ $summary['endpoints_updated'] }} updated, {{ $summary['responses_created'] }} responses created, and {{ $summary['responses_updated'] }} updated.</p>
                @if (($summary['revision_snapshots'] ?? 0) > 0)
                    <p class="import-undo-line">
                        <strong>{{ $summary['revision_snapshots'] }} {{ Str::plural('change', $summary['revision_snapshots']) }} made</strong>
                        @if ($importUndone)
                            <span class="state-chip enabled">Import undone</span>
                        @else
                            <button
                                class="text-button"
                                type="button"
                                wire:click="undoImport"
                                wire:confirm="Undo this import and restore: {{ $undoItems->implode(', ') }}? A new rollback revision will be kept for every restored item."
                            >Undo this import</button>
                        @endif
                    </p>
                @endif
                @if ($importedEndpoints->isNotEmpty())
                    <div class="imported-links">
                        @foreach ($importedEndpoints as $importedEndpoint)
                            <a href="{{ route('dashboard.endpoints.edit', $importedEndpoint) }}" wire:navigate>{{ $importedEndpoint->displayName() }} →</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
