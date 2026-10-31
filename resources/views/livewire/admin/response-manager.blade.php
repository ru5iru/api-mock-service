<div class="response-manager" data-draft-scope="responses" data-editing-response="{{ $editingId ?? 'new' }}" data-draft-baseline="{{ json_encode($this->editorDraftValues($formBaseline)) }}" data-draft-current="{{ json_encode($this->editorDraftValues()) }}" data-server-dirty="{{ $selectionDirty || $otherDraftsDirty ? 'true' : 'false' }}" data-context-catalog="{{ json_encode($contextCatalog) }}">
<section id="panel-response" role="region" aria-labelledby="response-pane-heading" x-show="workspaceTab === 'editor'">
@if ($responses->isEmpty())
    <p class="info-note" role="note" id="response-empty-note">No response yet. Add one so this endpoint can answer requests. Until then it returns a diagnostic 500.</p>
@endif
<div class="response-grid {{ $bodyMode === 'template' ? 'template-active' : '' }}">
    <div class="response-draft-picker">
        <label for="response-to-edit">Response to edit</label>
        <div>
            <select class="ui-select ui-select-dense" id="response-to-edit" x-data x-effect="$el.value = '{{ $editingId ?? '' }}'" wire:change="edit($event.target.value)" @disabled($responses->isEmpty())>
                <option value="" disabled @selected($editingId === null)>{{ $responses->isEmpty() ? 'No saved responses' : 'Choose a saved response' }}</option>
                @foreach ($responses as $response)
                    <option value="{{ $response->id }}" @selected($editingId === $response->id)>HTTP {{ $response->status_code }} · Response #{{ $response->id }}{{ $this->responseHasDraft($response->id) ? ' · Unsaved changes' : '' }}</option>
                @endforeach
            </select>
            <button class="button button-secondary button-small" type="button" wire:click="createNew">New response</button>
            @if ($editingId)
                <button class="text-button response-cancel-edit" type="button" wire:click="cancelEdit">Cancel edit</button>
            @endif
        </div>
        <p class="field-help" title="Switching keeps each response's draft. Cancel edit discards only the current draft.">Switching keeps each response's draft. Cancel edit discards only the current draft.</p>
    </div>
    <nav class="response-workspace-links" aria-label="Response workspace">
        <a href="#response-editor-form">Response editor</a>
        <a href="#response-selection-title">Selection &amp; pool <span>{{ $responses->count() }}</span></a>
    </nav>
    <form id="response-editor-form" class="card response-form" wire:submit="save" tabindex="-1">
        <div class="form-title-row">
            <h3>{{ $editingId ? 'Edit response #'.$editingId : 'Add response' }}</h3>
        </div>

        @php

            $responseErrors = collect($errors->getMessages())->reject(fn ($messages, $key) => preg_match('/^(selection_mode|sequence_on_exhaust|sequence_order|is_default|responseRules)/', $key));

        @endphp
        @if ($responseErrors->isNotEmpty())
            <div class="validation-panel error-panel" @if (! $globalSaveFailed || $globalSaveAttempt === '') role="alert" @endif>
                @if (! $globalSaveFailed || $globalSaveAttempt === '')<strong>Response changes were not saved.</strong>@endif
                @foreach ($responseErrors->flatten()->unique() as $message)<p>{{ $message }}</p>@endforeach
            </div>
        @endif



        <div class="field-row three response-properties">
            <div class="field">
                <label for="status-code">Status</label>
                <input id="status-code" type="number" min="100" max="599" wire:model="statusCode">
                @error('statusCode') <p class="field-error" data-error-for="{{ 'statusCode' }}">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="response-delay">Delay (ms)</label>
                <input id="response-delay" type="number" min="0" max="{{ config('mock.max_delay_ms') }}" wire:model="delayMs">
                @error('delayMs') <p class="field-error" data-error-for="{{ 'delayMs' }}">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="response-weight" title="Used only by Weighted selection. Sequence and rule-based selection ignore this value.">Weight</label>
                <input id="response-weight" type="number" min="1" wire:model="weight" aria-describedby="response-weight-help">
                @error('weight') <p class="field-error" data-error-for="{{ 'weight' }}">{{ $message }}</p> @enderror
            </div>
        </div>

        <p id="response-weight-help" class="field-help response-weight-note">Used only by Weighted selection. Sequence and rule-based selection ignore this value.</p>

        <div class="response-body-workspace">
        <fieldset class="field option-group response-body-toolbar" aria-labelledby="body-mode-label">
            <legend id="body-mode-label">Body</legend>
            <div class="segmented-control" role="group" aria-label="Response body mode">
                <button type="button" wire:click="setBodyMode('static')" aria-pressed="{{ $bodyMode === 'static' ? 'true' : 'false' }}">Static</button>
                <button type="button" wire:click="setBodyMode('template')" aria-pressed="{{ $bodyMode === 'template' ? 'true' : 'false' }}">Template</button>
            </div>
        </fieldset>

        @if ($bodyMode === 'static')
            <div class="field">
                <label for="response-body" class="response-source-caption" title="Body sent as-is"><span>Body sent as-is</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 16" /></svg><span class="response-source-filename" aria-hidden="true">{{ json_decode($body) !== null || trim($body) === 'null' ? 'response.json' : 'response.txt' }}</span></label>
                <div class="static-code-editor" data-static-code-editor>
                    <pre class="static-code-gutter" aria-hidden="true" wire:ignore></pre>
                    <pre class="static-code-highlight" aria-hidden="true" wire:ignore></pre>
                    <textarea id="response-body" class="code-input compact" rows="9" spellcheck="false" wire:model="body" data-static-code-input></textarea>
                </div>
                @error('body') <p class="field-error" data-error-for="{{ 'body' }}">{{ $message }}</p> @enderror
            </div>
        @else
            @php
                $decodedHeaders = json_decode($headersJson, true);
                $contentType = is_array($decodedHeaders)
                    ? collect($decodedHeaders)->first(fn ($value, $name) => strcasecmp((string) $name, 'Content-Type') === 0)
                    : null;
                $jsonContentType = $contentType === null || str_contains(strtolower((string) $contentType), 'json');
                $errorIssues = collect($templateIssues)->where('severity', 'error');
                $warningIssues = collect($templateIssues)->where('severity', 'warning');
                $builderUnavailableMessage = "Builder unavailable. This template uses features the builder can't show — edit as JSON";
            @endphp

            @if (! $jsonContentType)
                <div class="validation-panel warning-panel" role="status">
                    <strong>JSON template with a non-JSON Content-Type</strong>
                    <p>The rendered body is JSON, but the configured Content-Type is {{ $contentType }}. Update the header if clients should parse it as JSON.</p>
                </div>
            @endif

            <div class="template-toolbar response-template-toolbar">
                <div class="segmented-control" role="group" aria-label="Template editor view">
                    <span class="disabled-tooltip" @if (! $builderSupported) tabindex="0" aria-label="{{ $builderUnavailableMessage }}" title="{{ $builderUnavailableMessage }}" @endif>
                        <button type="button" wire:click="setEditorView('builder')" aria-pressed="{{ $editorView === 'builder' ? 'true' : 'false' }}" @disabled(! $builderSupported)>Builder</button>
                    </span>
                    <button type="button" wire:click="setEditorView('json')" aria-pressed="{{ $editorView === 'json' ? 'true' : 'false' }}">JSON</button>
                </div>
                <button class="text-button" type="button" wire:click="insertExample">Example</button>
            </div>

            <div class="field-row three template-options" role="group" aria-label="Template generation settings">
                <div class="field">
                    <label for="template-locale">Locale</label>
                    <select class="ui-select ui-select-dense" id="template-locale" wire:model.live="locale">
                        @foreach ($templateLocales as $availableLocale)
                            <option value="{{ $availableLocale }}">{{ $availableLocale }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="template-seed-mode">Seed</label>
                    <select class="ui-select ui-select-dense" id="template-seed-mode" wire:model.live="seedMode">
                        <option value="random">Random</option>
                        <option value="fixed">Fixed</option>
                        <option value="request">Request signature</option>
                    </select>
                </div>
                @if ($seedMode === 'fixed')
                    <div class="field">
                        <label for="template-seed">Seed value</label>
                        <input id="template-seed" type="number" wire:model.live.debounce.400ms="seed">
                        @error('seed') <p class="field-error" data-error-for="{{ 'seed' }}">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="field template-seed-note">
                        <span class="field-help">{{ $seedMode === 'request' ? 'The same request signature produces the same output.' : 'Regenerate creates fresh values.' }}</span>
                    </div>
                @endif
            </div>

            @if ($editorView === 'builder')
                <section class="schema-builder response-schema-workspace" aria-labelledby="schema-builder-title">
                    <div class="section-heading section-heading-inline">
                        <div>
                            <h3 id="schema-builder-title">Schema</h3>
                            <p>Define fields for the generated JSON response.</p>
                        </div>
                        <div class="segmented-control" role="group" aria-label="Schema root type">
                            <button type="button" wire:click="$set('builderSchema.root', 'object')" aria-pressed="{{ ($builderSchema['root'] ?? 'object') === 'object' ? 'true' : 'false' }}">Object</button>
                            <button type="button" wire:click="$set('builderSchema.root', 'list')" aria-pressed="{{ ($builderSchema['root'] ?? 'object') === 'list' ? 'true' : 'false' }}">List of objects</button>
                        </div>
                    </div>

                    @if (($builderSchema['root'] ?? 'object') === 'list')
                        <div class="builder-root-count">
                            <label class="select-field">
                                <span class="select-caption">Count</span>
                                <select class="ui-select ui-select-dense" wire:model.live="builderSchema.count_mode">
                                    <option value="fixed">Fixed</option>
                                    <option value="range">Min–max</option>
                                </select>
                            </label>
                            @if (($builderSchema['count_mode'] ?? 'fixed') === 'range')
                                <label class="compact-input"><span>Min</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="builderSchema.min"></label>
                                <label class="compact-input"><span>Max</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="builderSchema.max"></label>
                            @else
                                <label class="compact-input"><span>Items</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="builderSchema.count"></label>
                            @endif
                        </div>
                    @endif

                    <div class="schema-rows">
                        @foreach (($builderSchema['fields'] ?? []) as $fieldIndex => $fieldRow)
                            @include('livewire.admin.partials.schema-row', [
                                'row' => $fieldRow,
                                'rowPath' => 'fields.'.$fieldIndex,
                                'parentPath' => 'fields',
                                'rowIndex' => $fieldIndex,
                                'depth' => 0,
                                'showKey' => true,
                            ])
                        @endforeach
                    </div>
                    <button class="button button-secondary button-small add-schema-row" type="button" wire:click="addSchemaRow('fields')"><span aria-hidden="true">+</span> Add field</button>
                </section>
            @else
                <div class="field template-json-field" data-template-editor-root>
                    <div class="label-row">
                        <label for="response-template">JSON template</label>
                        <span class="loading-label" wire:loading.delay wire:target="template">Validating…</span>
                    </div>
                    <textarea
                        id="response-template"
                        class="code-input template-json-editor"
                        rows="12"
                        spellcheck="false"
                        wire:model.live.debounce.400ms="template"
                        data-template-editor
                        role="combobox"
                        aria-autocomplete="list"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-controls="template-method-suggestions"
                        aria-describedby="template-editor-help @error('template') response-template-error @enderror"
                    ></textarea>
                    <div id="template-method-suggestions" class="template-autocomplete" data-template-autocomplete role="listbox" aria-label="Faker method suggestions" wire:ignore hidden></div>
                    <span id="template-editor-help" class="field-help">Type $ or {{ '{{' }} to insert a supported Faker method.</span>
                    @error('template') <p id="response-template-error" class="field-error" data-error-for="{{ 'template' }}">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="template-validation" id="response-template-validation">
            @if ($errorIssues->isNotEmpty())
                <div class="validation-panel error-panel" role="alert" data-error-for="template">
                    <strong>Fix template errors before saving</strong>
                    <ul>
                        @foreach ($errorIssues as $issue)
                            <li>
                                <code>{{ $issue['code'] }}</code> at {{ $issue['path'] }} ({{ $issue['line'] }}:{{ $issue['col'] }}): {{ $issue['message'] }}
                                @if ($issue['suggestion'])
                                    <button class="text-button template-quick-fix" type="button" wire:click="applyTemplateSuggestion({{ Illuminate\Support\Js::from($issue['message']) }}, {{ Illuminate\Support\Js::from($issue['suggestion']) }})">Use {{ $issue['suggestion'] }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($warningIssues->isNotEmpty())
                <div class="validation-panel warning-panel" role="status">
                    <strong>Template warnings</strong>
                    <ul>
                        @foreach ($warningIssues as $issue)
                            <li>
                                <code>{{ $issue['code'] }}</code> at {{ $issue['path'] }}: {{ $issue['message'] }}
                                @if ($issue['suggestion'])
                                    <button class="text-button template-quick-fix" type="button" wire:click="applyTemplateSuggestion({{ Illuminate\Support\Js::from($issue['message']) }}, {{ Illuminate\Support\Js::from($issue['suggestion']) }})">Use {{ $issue['suggestion'] }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            </div>

            <section class="template-preview code-block-wrap" aria-labelledby="template-preview-title" aria-describedby="response-preview-notice">
                <div class="template-preview-status" role="status" wire:loading wire:target="previewTemplate">Rendering sample…</div>
                <div class="code-block-heading">
                    <span id="template-preview-title">Preview <span class="template-sample-label">Sample data</span></span>
                    <button type="button" wire:click="previewTemplate" wire:loading.attr="disabled" wire:target="previewTemplate">Regenerate</button>
                </div>
                <p class="info-note" id="response-preview-notice">{{ \App\Services\Templates\TemplateContext::PREVIEW_NOTICE }} Context tokens require the JSON editor.</p>
                @if ($previewOutput !== '')
                    <pre>{{ $previewOutput }}</pre>
                    <div class="template-preview-meta"><span>{{ $previewBytes }} B</span><span>{{ number_format($previewRenderMs, 2) }} ms</span></div>
                @else
                    <div class="template-preview-empty">No preview yet — fix any errors, then select Regenerate.</div>
                @endif
            </section>
        @endif
        </div>

        <div class="field response-headers-field">
            <label for="response-headers">Headers <span>JSON object</span></label>
            <textarea id="response-headers" class="code-input compact" rows="3" spellcheck="false" wire:model="headersJson"></textarea>
            @error('headersJson') <p class="field-error" data-error-for="{{ 'headersJson' }}">{{ $message }}</p> @enderror
        </div>

        <details class="history-disclosure response-fault-settings" data-disclosure wire:ignore.self wire:key="response-fault-{{ $editingId ?? 'new' }}">
            <summary><x-disclosure-chevron /><strong>Fault injection</strong>@if ($faultEnabled)<x-badge variant="warning">Enabled</x-badge><span class="fault-summary">{{ $faultType === 'timeout' ? 'Long delay' : ucfirst(str_replace('_', ' ', $faultType)) }} · <span x-data x-text="$wire.faultProbability === '' || $wire.faultProbability == null ? '—' : $wire.faultProbability">{{ $faultProbability }}</span>%</span>@else<span class="fault-summary">Off</span>@endif</summary>
            <div class="response-fault-controls" role="group" aria-label="Fault settings">
            <p class="field-help">Update response or Save changes applies these settings.</p>
            <label class="toggle-inline"><input type="checkbox" wire:model.live="faultEnabled"><span>Enable fault injection</span></label>
            <p class="field-help">Applied after response selection. <x-help-tip title="Primary response faults" label="Faulted calls still count as matches. Probability is rolled for each selected response. Delay adds to the normal response delay; an empty maximum uses a fixed delay. Template previews remain normal." /></p>
            <div class="field-row two">
                <div class="field">
                    <label for="response-fault-type">Fault type</label>
                    <select class="ui-select" id="response-fault-type" wire:model.live="faultType" @disabled(! $faultEnabled)>
                        <option value="delay">Delay</option>
                        <option value="malformed_body">Malformed body (JSON/XML)</option>
                        <option value="truncated_body">Truncated body</option>
                        <option value="timeout">Timeout (long delay)</option>
                    </select>
                    @error('faultType') <p class="field-error" data-error-for="{{ 'faultType' }}">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="response-fault-probability">Probability (%)</label>
                    <input id="response-fault-probability" type="number" min="0" max="100" wire:model="faultProbability" @disabled(! $faultEnabled)>
                    @error('faultProbability') <p class="field-error" data-error-for="{{ 'faultProbability' }}">{{ $message }}</p> @enderror
                </div>
            </div>
            @if (in_array($faultType, ['delay', 'timeout'], true))
                <div class="field-row two">
                    <div class="field"><label for="response-fault-delay-min">Delay min (ms)</label><input id="response-fault-delay-min" type="number" min="0" max="120000" wire:model="faultDelayMsMin" @disabled(! $faultEnabled)>@error('faultDelayMsMin') <p class="field-error" data-error-for="{{ 'faultDelayMsMin' }}">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="response-fault-delay-max">Delay max (ms) <span>optional</span></label><input id="response-fault-delay-max" type="number" min="0" max="120000" wire:model="faultDelayMsMax" @disabled(! $faultEnabled)>@error('faultDelayMsMax') <p class="field-error" data-error-for="{{ 'faultDelayMsMax' }}">{{ $message }}</p> @enderror</div>
                </div>
            @endif
            @if ($faultType === 'timeout')
                <p class="info-note">Approximation: a long delay, capped at 120 seconds. <x-help-tip title="Timeout approximation" label="Set the delay longer than your client's timeout. The proxy or PHP worker may finish first; this does not hold a connection open indefinitely or reset TCP." /></p>
            @elseif ($faultType === 'malformed_body')
                <p class="field-help">Replaces JSON or XML with an unfinished document.</p>
            @elseif ($faultType === 'truncated_body')
                <p class="field-help">Returns the first half of the rendered body's bytes.</p>
            @endif
            </div>
        </details>

        <button class="button button-primary button-full" type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($bodyMode === 'template' && collect($templateIssues)->contains(fn ($issue) => $issue['severity'] === 'error'))>
            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update response' : 'Add response' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
    <div class="response-list" data-selection-mode="{{ $selectionMode }}">
        @php
            $savedWeightTotal = $responses->sum(fn ($item) => max(1, (int) $item->weight));
        @endphp
        <section class="card selection-settings" aria-labelledby="response-selection-title">
            <div class="selection-workspace-heading"><h3 id="response-selection-title" tabindex="-1">Response selection</h3><span>{{ $responses->count() }} {{ Str::plural('response', $responses->count()) }}</span></div>
            <div class="segmented-control" role="group" aria-label="Response selection mode">
                @foreach (['weighted' => 'Weighted', 'sequence' => 'Sequence', 'rule' => 'Rule-based'] as $mode => $label)
                    <button type="button" wire:click="setSelectionMode('{{ $mode }}')" aria-pressed="{{ $selectionMode === $mode ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="field-help">{{ $selectionDirty ? 'Unsaved selection changes.' : 'Selection saved.' }} <x-help-tip title="Saving selection" label="Save selection applies this mode and order. Save changes also saves these drafts. Weighted is the default mode." /></p>
            <p class="selection-mode-help">{{ match ($selectionMode) { 'sequence' => 'Serve responses in the order below. Position is scoped to the active Environment.', 'rule' => 'Evaluate conditions after endpoint matching; choose the first matching response or the fallback.', default => 'Choose randomly in proportion to each saved response weight.' } }}</p>
            @if ($selectionMode === 'weighted')
                <p class="info-note saved-weight-note">Chances below use saved weights, rounded to one decimal. Save response weight edits before relying on these values.</p>
            @endif
            @if ($selectionMode === 'sequence')
                <div class="sequence-runtime" aria-label="Sequence runtime for {{ $selectionEnvironment->name }}">
                <label class="field" for="sequence-on-exhaust"><span>On exhaust</span>
                    <select class="ui-select" id="sequence-on-exhaust" wire:model.live="sequenceOnExhaust">
                        <option value="repeat_last">Repeat last</option><option value="loop">Loop</option><option value="not_found">Not found</option>
                    </select>
                </label>
                <p class="field-help" role="status">@if ($responses->isEmpty())No responses configured for {{ $selectionEnvironment->name }}.@elseif ($nextSequencePosition === null)Sequence exhausted for {{ $selectionEnvironment->name }}.@else Next response: {{ $nextSequencePosition }} of {{ $responses->count() }} for {{ $selectionEnvironment->name }}.@endif Matched calls: {{ $totalMatchCount }}. <x-help-tip title="Sequence position" label="The next position follows the On exhaust policy. Reset affects only the active environment and retains match counts." /></p>
                <button class="button button-tertiary button-small" type="button" wire:click="resetSequence" data-confirm-title="Confirm response action" data-confirm="Reset the sequence position for {{ $selectionEnvironment->name }} only? Match counts and other environments will be retained.">Reset sequence</button>
                </div>
            @endif
            @php
                $selectionErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => preg_match('/^(selection_mode|sequence_on_exhaust|sequence_order|is_default|responseRules)/', $key));
            @endphp
            @if ($selectionErrors->isNotEmpty())
                <div class="validation-panel error-panel" role="alert">
                    @foreach ($selectionErrors as $path => $messages)
                        @foreach ($messages as $message)<p>@if (str_starts_with($path, 'responseRules.'))<button type="button" class="text-button" data-focus-error="{{ $path }}">{{ $message }}</button>@else{{ $message }}@endif</p>@endforeach
                    @endforeach
                </div>
            @endif
            <button class="button button-primary button-small" type="button" wire:click="saveSelection" wire:loading.attr="disabled" wire:target="saveSelection">Save selection</button>
            @if ($selectionMode !== 'weighted')
                <section class="signature-panel selection-preview" aria-labelledby="selection-preview-title">
                    <h3 id="selection-preview-title">Selection preview <x-badge>{{ $selectionDirty ? 'Draft' : 'Saved' }}</x-badge></h3>
                    @if ($selectionMode === 'sequence')
                        <p>{{ $nextSequenceResponse ? 'Next: response #'.$nextSequenceResponse->id.' · HTTP '.$nextSequenceResponse->status_code : 'Next: HTTP 404 · sequence exhausted or no responses' }}</p>
                    @else
                        <ol>
                            @foreach ($rulePreview as $candidate)
                                <li>Response #{{ $candidate->id }} · priority {{ collect($responseRules[$candidate->id])->min('priority') }}
                                    <ul>@foreach ($responseRules[$candidate->id] as $condition)<li>{{ $condition['field_type'] }} {{ $condition['field_name'] }} {{ $condition['operator'] }} {{ $condition['operator'] === 'exists' ? '' : $condition['value'] }}</li>@endforeach</ul>
                                </li>
                            @endforeach
                        </ol>
                        <p><x-badge variant="info">Fallback</x-badge> {{ $fallbackResponseId ? 'Response #'.$fallbackResponseId : 'Choose exactly one fallback before saving.' }}</p>
                    @endif
                    <p class="field-help">Preview only. <x-help-tip title="Selection preview" label="Preview does not advance counters. In rule mode, all conditions on one response must match; the first full match wins." /></p>
                </section>
            @endif
        </section>

        @forelse ($responses as $responseIndex => $response)
            <article class="card response-card selection-response-card {{ $editingId === $response->id ? 'selected' : '' }}" wire:key="response-{{ $response->id }}" data-schema-row data-schema-parent="responses" data-schema-index="{{ $responseIndex }}" data-schema-target="selection">
                @if ($selectionMode !== 'weighted')
                    <span class="schema-drag-handle" draggable="true" data-schema-drag-handle title="Drag to reorder responses" aria-hidden="true">⋮⋮</span>

                @endif
                <div class="response-status">
                    <x-radio name="configured-response" value="{{ $response->id }}" wire:click="edit({{ $response->id }})" :checked="$editingId === $response->id" aria-label="Select response {{ $response->id }} (HTTP {{ $response->status_code }}) for editing" />
                    <strong>{{ $response->status_code }}</strong>
                </div>
                <div class="response-description response-row-summary" title="{{ ($response->body_mode ?? 'static') === 'template' ? 'JSON response template' : ($response->body ?: 'Empty body') }}">
                    <x-response-identity :response="$response" />
                    @if ($this->responseHasDraft($response->id))<x-badge variant="warning">Unsaved changes</x-badge>@endif
                    @if ($selectionMode === 'weighted')<span class="response-selection-summary">Weight {{ $response->weight }} · {{ number_format(max(1, (int) $response->weight) / max(1, $savedWeightTotal) * 100, 1) }}% of saved pool</span>@endif
                    @if ($selectionMode === 'sequence')<x-badge variant="info">Position {{ $responseIndex + 1 }}</x-badge>@endif
                    @if ($selectionMode === 'rule')
                        @if ($fallbackResponseId === $response->id)
                            <x-badge variant="info">Fallback</x-badge>
                        @else
                            {{ count($responseRules[$response->id] ?? []) }} {{ Str::plural('condition', count($responseRules[$response->id] ?? [])) }}
                        @endif
                    @endif
                </div>
                <div class="endpoint-actions">
                    @if ($selectionMode !== 'weighted')
                        <div class="selection-move-actions">
                            <button class="icon-button" type="button" wire:click="moveSelectionResponse({{ $responseIndex }}, -1)" @disabled($responseIndex === 0) aria-label="Move response {{ $response->id }} earlier">↑</button>
                            <button class="icon-button" type="button" wire:click="moveSelectionResponse({{ $responseIndex }}, 1)" @disabled($responseIndex === $responses->count() - 1) aria-label="Move response {{ $response->id }} later">↓</button>
                        </div>
                    @endif
                    <button class="icon-button" type="button" wire:click="edit({{ $response->id }})">Edit</button>
                    <details class="overflow-menu" data-menu>
                        <summary aria-label="More actions for response {{ $response->id }}">•••</summary>
                        <div>
                    <button type="button" x-on:click="switchTab('callback')" wire:click="editCallback({{ $response->id }})" aria-label="Configure callback for response {{ $response->id }}">Callback</button>
                    <button class="danger-text" type="button" wire:click="delete({{ $response->id }})" data-confirm-title="Confirm response action" data-confirm="Delete response #{{ $response->id }} (HTTP {{ $response->status_code }}) from this endpoint? {{ $selectionMode === 'rule' && $response->is_default ? 'It is the saved fallback: deletion will be blocked until you select and save another fallback.' : 'It will no longer be available for matching requests.' }}">Delete</button>

                        </div>
                    </details>
                </div>
                @if ($selectionMode === 'rule')
                    <div class="selection-response-details">
                        <label class="toggle-inline"><x-radio name="fallback-response" value="{{ $response->id }}" wire:click="setFallback({{ $response->id }})" :checked="$fallbackResponseId === $response->id" aria-label="Use response {{ $response->id }} as default fallback" /><span>Default / fallback</span></label>
                        <details class="history-disclosure" data-disclosure wire:ignore.self wire:key="response-conditions-{{ $response->id }}" @if ($fallbackResponseId !== $response->id) open @endif>
                            <summary><x-disclosure-chevron /><strong>Conditions</strong></summary>
                            <p class="field-help">Rules select after endpoint matching. <span wire:ignore><x-help-tip title="Match before response selection" label="The request must first match this endpoint by method, path/query, retained headers and body; rules then choose among its responses. A rule cannot make a different request match. To vary a header or query value, exclude it in Matching first, then choose the response with a rule." /></span></p>
                            @if ($fallbackResponseId === $response->id)<p class="field-help">Fallback is selected only when no other response matches; its conditions are not evaluated.</p>@endif
                            @foreach ($responseRules[$response->id] ?? [] as $ruleIndex => $condition)
                                @php
                                    $conditionPath = 'responseRules.'.$response->id.'.'.$ruleIndex;
                                @endphp
                                <div class="rule-condition-row" wire:key="condition-{{ $response->id }}-{{ $ruleIndex }}">
                                    <div class="field-row three">
                                        <label class="field"><span>Field type</span><select class="ui-select" wire:model.live="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_type"><option value="header">Header</option><option value="query">Query</option><option value="body_json_path">Body JSON path</option></select>@error($conditionPath.'.field_type')<span class="field-error" data-error-for="{{ $conditionPath.'.field_type' }}" role="alert">{{ $message }}</span>@enderror</label>
                                        <label class="field"><span>{{ $condition['field_type'] === 'body_json_path' ? 'Body JSON path' : 'Field name' }}</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_name" aria-describedby="rule-name-help-{{ $response->id }}-{{ $ruleIndex }}" placeholder="{{ match ($condition['field_type']) { 'header' => 'X-Mode', 'query' => 'status', default => 'user.id' } }}"><small class="field-help" id="rule-name-help-{{ $response->id }}-{{ $ruleIndex }}">{{ match ($condition['field_type']) { 'header' => 'HTTP header name, case-insensitive.', 'query' => 'Query parameter name from the URL.', default => 'Dot-separated JSON path, for example user.id or items.0.sku.' } }}</small>@error($conditionPath.'.field_name')<span class="field-error" data-error-for="{{ $conditionPath.'.field_name' }}" role="alert">{{ $message }}</span>@enderror</label>
                                        <label class="field"><span>Operator</span><select class="ui-select" wire:model.live="responseRules.{{ $response->id }}.{{ $ruleIndex }}.operator">@foreach (['equals', 'contains', 'regex', 'exists'] as $operator)<option value="{{ $operator }}">{{ ucfirst($operator) }}</option>@endforeach</select>@error($conditionPath.'.operator')<span class="field-error" data-error-for="{{ $conditionPath.'.operator' }}" role="alert">{{ $message }}</span>@enderror</label>
                                    </div>
                                    <div class="field-row three">
                                        <label class="field"><span>Value</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.value" @disabled($condition['operator'] === 'exists') @if ($condition['operator'] === 'regex') aria-describedby="rule-value-help-{{ $response->id }}-{{ $ruleIndex }}" @endif>@if ($condition['operator'] === 'regex')<small class="field-help" id="rule-value-help-{{ $response->id }}-{{ $ruleIndex }}">Include regex delimiters, for example <code>/^test$/i</code>.</small>@endif@error($conditionPath.'.value')<span class="field-error" data-error-for="{{ $conditionPath.'.value' }}" role="alert">{{ $message }}</span>@enderror</label>
                                        <label class="field"><span>Priority (lower first)</span><input type="number" min="0" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.priority">@error($conditionPath.'.priority')<span class="field-error" data-error-for="{{ $conditionPath.'.priority' }}" role="alert">{{ $message }}</span>@enderror</label>
                                        <button class="icon-button danger" type="button" wire:click="removeRule({{ $response->id }}, {{ $ruleIndex }})" aria-label="Remove condition {{ $ruleIndex + 1 }} from response {{ $response->id }}">Remove</button>
                                    </div>
                                </div>
                            @endforeach
                            <button class="button button-tertiary button-small" type="button" wire:click="addRule({{ $response->id }})">Add condition</button>
                        </details>
                    </div>
                @endif
                <details class="history-disclosure response-row-details" data-disclosure wire:ignore.self wire:key="response-details-{{ $response->id }}">
                    <summary><x-disclosure-chevron /><strong>Details</strong></summary>
                    <div class="response-row-detail-content">
                        @if ($response->external_label !== null)<p class="field-help">Imported example: <strong>{{ $response->external_label }}</strong></p>@endif
                        <code class="response-body-summary">{{ ($response->body_mode ?? 'static') === 'template' ? 'JSON response template' : (Str::limit(preg_replace('/\s+/', ' ', $response->body ?? ''), 82) ?: 'Empty body') }}</code>
                        <div class="metadata-row"><span>{{ $response->delay_ms }} ms delay</span><span>{{ count($response->headers ?? []) }} headers</span>@if ($response->callback_enabled)<x-badge variant="info">Callback enabled</x-badge>@endif @if ($response->fault_enabled)<x-badge variant="warning">Fault: {{ $response->fault_type === 'timeout' ? 'long delay' : str_replace('_', ' ', $response->fault_type) }}</x-badge>@endif</div>

                <details class="history-disclosure response-history" data-disclosure>
                    <summary><x-disclosure-chevron /><span><strong>History</strong><small>Compare or restore response versions.</small></span></summary>
                    <livewire:admin.revision-history entity-type="response" :entity-id="$response->id" :key="'response-history-'.$response->id" />
                </details>

                    </div>
                </details>
            </article>
        @empty
            <div class="empty-state small card">
                <h3>No responses yet</h3>
                <p>Add at least one response before invoking this endpoint.</p>
            </div>
        @endforelse
    </div>


</div>
</section>
<section id="panel-callback" role="tabpanel" aria-labelledby="tab-callback" x-show="activeTab === 'callback'" x-cloak>
    <p class="field-help">New callback deliveries use worker-time values, not a snapshot taken when the mock was called.</p>
    <div class="callback-workspace">
    <div class="callback-response-list" aria-label="Response callbacks">
        @forelse ($responses as $responseIndex => $response)
            @php
                $rowCallbackEnabled = $editingId === $response->id ? $callbackEnabled : ($pendingFormDrafts[$response->id]['state']['callbackEnabled'] ?? $response->callback_enabled);
            @endphp
            <div class="card callback-response-row {{ $editingId === $response->id ? 'selected' : '' }}" wire:key="callback-row-{{ $response->id }}">
                <x-badge>HTTP {{ $response->status_code }}</x-badge>
                <x-response-identity :response="$response" />
                @if ($this->responseHasDraft($response->id))<x-badge variant="warning">Unsaved changes</x-badge>@endif
                <span class="response-row-summary">@if ($selectionMode === 'sequence')Position {{ $responseIndex + 1 }}@elseif ($selectionMode === 'rule'){{ $fallbackResponseId === $response->id ? 'Fallback' : count($responseRules[$response->id] ?? []).' conditions' }}@else Weight {{ $response->weight }}@endif</span>
                <x-badge :variant="$rowCallbackEnabled ? 'success' : 'neutral'">{{ $rowCallbackEnabled ? 'Enabled' : 'Disabled' }}</x-badge>
                <button class="icon-button" type="button" wire:click="editCallback({{ $response->id }}, {{ $rowCallbackEnabled ? 'false' : 'true' }})" aria-label="Configure callback for response {{ $response->id }}">{{ $rowCallbackEnabled ? 'Edit callback' : 'Enable callback' }}</button>
            </div>
        @empty
            <div class="card empty-state"><h3>Add a response first</h3><p>Callbacks belong to individual responses.</p><button class="button button-secondary" type="button" x-on:click="switchTab('response')">Configure responses</button></div>
        @endforelse
    </div>
    @if ($responses->isNotEmpty() && $editingId)
        <form class="card callback-form" wire:submit="saveCallback">
            <div class="form-title-row"><h3>Callback for response #{{ $editingId }}</h3><span class="field-help">Save callback applies only these settings.</span></div>
            @if ($errors->any())<div class="validation-panel error-panel" role="alert">@foreach (array_unique($errors->all()) as $message)<p>{{ $message }}</p>@endforeach</div>@endif
            @include('livewire.admin.partials.callback-settings')
            <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveCallback">Save callback</button>
        </form>
    @elseif ($responses->isNotEmpty())
        <p class="field-help">Select a response to configure its callback.</p>
    @endif
    </div>
</section>
</div>
