<div>
    <header class="page-heading compact">
        <div>
            <span class="eyebrow">Portable configuration</span>
            <h1>Import &amp; export</h1>
            <p>Move endpoint definitions safely between MockDeck installations.</p>
        </div>
        <a class="button button-secondary" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Back to endpoints</a>
    </header>

    <div class="transfer-grid">
        <section class="card transfer-card" aria-labelledby="export-heading">
            <div class="card-heading tight">
                <span class="step-number">1</span>
                <div>
                    <h2 id="export-heading">Export configuration</h2>
                    <p>Download all endpoints or choose a portable subset.</p>
                </div>
            </div>

            <div class="selection-toolbar">
                <strong>{{ count($selectedEndpointUuids) }} selected</strong>
                <span>
                    <button class="text-button" type="button" wire:click="selectAll">Select all</button>
                    <button class="text-button" type="button" wire:click="clearSelection">Clear</button>
                </span>
            </div>

            <div class="config-endpoint-list" aria-label="Endpoints available to export">
                @forelse ($endpoints as $endpoint)
                    <label class="config-endpoint-option" wire:key="export-endpoint-{{ $endpoint->uuid }}">
                        <input type="checkbox" value="{{ $endpoint->uuid }}" wire:model="selectedEndpointUuids">
                        <span>
                            <strong>{{ $endpoint->name ?: 'Untitled endpoint' }}</strong>
                            <small>{{ $endpoint->method }} · {{ $endpoint->responses_count }} {{ Str::plural('response', $endpoint->responses_count) }}</small>
                        </span>
                    </label>
                @empty
                    <p class="muted small">Create an endpoint before exporting a configuration.</p>
                @endforelse
            </div>
            @error('selectedEndpointUuids') <p class="field-error">{{ $message }}</p> @enderror

            <label class="toggle-row transfer-toggle">
                <span>
                    <strong>Redact request secrets</strong>
                    <small>Removes auth, cookies, API-key headers, and sensitive query values.</small>
                </span>
                <input type="checkbox" wire:model.live="redactSecrets">
            </label>

            @if (! $redactSecrets)
                <label class="confirmation-row">
                    <input type="checkbox" wire:model="confirmSensitiveExport">
                    <span>I understand this export may contain credentials.</span>
                </label>
            @endif
            @error('redactSecrets') <p class="field-error">{{ $message }}</p> @enderror
            @error('export') <p class="field-error">{{ $message }}</p> @enderror

            <div class="form-actions transfer-actions">
                <button class="button button-secondary" type="button" wire:click="exportSelected" wire:loading.attr="disabled">
                    Export selected JSON
                </button>
                <button class="button button-primary" type="button" wire:click="exportAll" wire:loading.attr="disabled">
                    Export all JSON
                </button>
            </div>
        </section>

        <section class="card transfer-card" aria-labelledby="import-heading">
            <div class="card-heading tight">
                <span class="step-number">2</span>
                <div>
                    <h2 id="import-heading">Import configuration</h2>
                    <p>Preview validation and conflicts before anything is written.</p>
                </div>
            </div>

            <form wire:submit="preview">
                <div class="field">
                    <label for="config-file">MockDeck JSON file</label>
                    <input id="config-file" type="file" wire:model="configFile" accept=".json,application/json,application/vnd.mockdeck.config+json">
                    <small class="field-help">Maximum {{ number_format(config('mock.portable_config.max_bytes') / 1048576, 1) }} MB. The preview is read-only.</small>
                    @error('configFile') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <fieldset class="field option-group">
                    <legend>Import mode</legend>
                    @foreach ($modes as $importMode)
                        <label class="radio-option">
                            <input type="radio" value="{{ $importMode->value }}" wire:model.live="mode">
                            <span>
                                <strong>{{ $importMode->label() }}</strong>
                                <small>
                                    @if ($importMode->value === 'create-only') Stop when any UUID already exists.
                                    @elseif ($importMode->value === 'upsert') Update endpoints and responses with matching UUIDs.
                                    @else Generate new UUIDs for every imported record.
                                    @endif
                                </small>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ($mode === 'upsert')
                    <label class="confirmation-row">
                        <input type="checkbox" wire:model.live="replaceResponses">
                        <span>Delete local responses omitted from updated endpoints.</span>
                    </label>
                @endif

                <button class="button button-secondary button-full" type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="preview,configFile">Preview import</span>
                    <span wire:loading wire:target="preview,configFile">Checking configuration…</span>
                </button>
            </form>
        </section>
    </div>

    @if ($plan !== [])
        <section class="card import-preview" aria-labelledby="preview-heading" aria-live="polite">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Dry run</span>
                    <h2 id="preview-heading">Import preview</h2>
                    <p>No database changes have been made.</p>
                </div>
            </div>

            <dl class="summary-grid">
                <div><dt>Endpoints</dt><dd>{{ $plan['counts']['endpoints'] }}</dd></div>
                <div><dt>Responses</dt><dd>{{ $plan['counts']['responses'] }}</dd></div>
                <div><dt>Creates</dt><dd>{{ $plan['counts']['creates'] }}</dd></div>
                <div><dt>Updates</dt><dd>{{ $plan['counts']['updates'] }}</dd></div>
                <div><dt>Conflicts</dt><dd>{{ $plan['counts']['conflicts'] }}</dd></div>
                <div><dt>Warnings</dt><dd>{{ $plan['counts']['warnings'] }}</dd></div>
            </dl>

            @if ($plan['errors'] !== [])
                <div class="validation-panel error-panel" role="alert">
                    <strong>Resolve these errors before importing</strong>
                    <ul>
                        @foreach ($plan['errors'] as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($plan['warnings'] !== [])
                <div class="validation-panel warning-panel">
                    <strong>Review these warnings</strong>
                    <ul>
                        @foreach ($plan['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($plan['items'] !== [])
                <div class="table-scroll">
                    <table class="log-table import-table">
                        <thead><tr><th>Endpoint</th><th>Action</th><th>Match variant</th><th>Detail</th></tr></thead>
                        <tbody>
                            @foreach ($plan['items'] as $item)
                                <tr>
                                    <td><strong>{{ $item['name'] }}</strong><br><code>{{ $item['uuid'] }}</code></td>
                                    <td><span class="action-chip {{ $item['action'] }}">{{ ucfirst($item['action']) }}</span></td>
                                    <td>{{ $item['variant'] ?? '—' }}</td>
                                    <td>{{ $item['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($plan['warnings'] !== [] && $plan['can_apply'])
                <label class="confirmation-row preview-confirmation">
                    <input type="checkbox" wire:model="acknowledgeWarnings">
                    <span>I reviewed the warnings and want to continue.</span>
                </label>
            @endif

            @error('import') <p class="field-error" role="alert">{{ $message }}</p> @enderror

            <div class="form-actions">
                <button
                    class="button button-primary"
                    type="button"
                    wire:click="apply"
                    wire:loading.attr="disabled"
                    @disabled(! $plan['can_apply'] || ($plan['warnings'] !== [] && ! $acknowledgeWarnings))
                >
                    Apply import
                </button>
            </div>
        </section>
    @endif

    @if ($summary !== [])
        <section class="card import-preview" aria-live="polite">
            <span class="eyebrow">Completed</span>
            <h2>Import applied atomically</h2>
            <p class="muted">{{ $summary['endpoints_created'] }} endpoints created, {{ $summary['endpoints_updated'] }} updated, {{ $summary['responses_created'] }} responses created, and {{ $summary['responses_updated'] }} updated.</p>
        </section>
    @endif
</div>
