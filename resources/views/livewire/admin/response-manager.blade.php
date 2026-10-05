<div class="response-manager" data-context-catalog="{{ json_encode($contextCatalog) }}">
<section id="panel-response" role="tabpanel" aria-labelledby="tab-response" x-show="activeTab === 'response'">
<div class="response-grid {{ $bodyMode === 'template' ? 'template-active' : '' }}">
    <div class="response-list">
        <section class="card selection-settings" aria-labelledby="response-selection-title">
            <h3 id="response-selection-title">Response selection</h3>
            <div class="segmented-control" role="group" aria-label="Response selection mode">
                @foreach (['weighted' => 'Weighted', 'sequence' => 'Sequence', 'rule' => 'Rule-based'] as $mode => $label)
                    <button type="button" wire:click="setSelectionMode('{{ $mode }}')" aria-pressed="{{ $selectionMode === $mode ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="field-help">Unsaved selection draft. <x-help-tip title="Saving selection" label="Save selection applies this mode and order. Save changes also saves these drafts. Weighted is the default mode." /></p>
            @if ($selectionMode === 'sequence')
                <label class="field" for="sequence-on-exhaust"><span>On exhaust</span>
                    <select class="ui-select" id="sequence-on-exhaust" wire:model.live="sequenceOnExhaust">
                        <option value="repeat_last">Repeat last</option><option value="loop">Loop</option><option value="not_found">Not found</option>
                    </select>
                </label>
                <p class="field-help" role="status">Currently on call {{ $sequencePosition + 1 }} of {{ $responses->count() }} for {{ $selectionEnvironment->name }}. <x-help-tip title="Sequence position" label="This is the next call position. Reset affects only the active environment and retains match counts." /></p>
                <button class="button button-tertiary button-small" type="button" wire:click="resetSequence" data-confirm-title="Confirm response action" data-confirm="Reset the sequence position for {{ $selectionEnvironment->name }} only? Match counts and other environments will be retained.">Reset sequence</button>
            @endif
            @php
                $selectionErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => preg_match('/^(selection_mode|sequence_on_exhaust|sequence_order|is_default|responseRules)/', $key));
            @endphp
            @if ($selectionErrors->isNotEmpty())
                <div class="validation-panel error-panel" role="alert">
                    @foreach ($selectionErrors->flatten() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif
            <button class="button button-primary button-small" type="button" wire:click="saveSelection" wire:loading.attr="disabled" wire:target="saveSelection">Save selection</button>
            @if ($selectionMode !== 'weighted')
                <section class="signature-panel selection-preview" aria-labelledby="selection-preview-title">
                    <h3 id="selection-preview-title">Selection preview <x-badge>Draft</x-badge></h3>
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
                    <x-radio name="configured-response" value="{{ $response->id }}" wire:click="edit({{ $response->id }})" :checked="$editingId === $response->id || ($responses->count() === 1 && $editingId === null)" aria-label="Select response {{ $response->status_code }} for editing" />
                    <strong>{{ $response->status_code }}</strong>
                </div>
                <div class="response-description response-row-summary" title="{{ ($response->body_mode ?? 'static') === 'template' ? 'JSON response template' : ($response->body ?: 'Empty body') }}">
                    @if ($selectionMode === 'weighted')Weight {{ $response->weight }}@endif
                    @if ($selectionMode === 'sequence')<x-badge variant="info">Position {{ $responseIndex + 1 }}</x-badge>@endif
                    @if ($selectionMode === 'rule')
                        @if ($fallbackResponseId === $response->id)
                            <x-badge variant="info">Fallback</x-badge>
                        @else
                            {{ count($responseRules[$response->id] ?? []) }} conditions
                        @endif
                    @endif
                </div>
                <div class="endpoint-actions">
                    <button class="icon-button" type="button" wire:click="edit({{ $response->id }})">Edit</button>
                    <details class="overflow-menu" data-menu>
                        <summary aria-label="More actions for response {{ $response->id }}">•••</summary>
                        <div>
                    <button type="button" x-on:click="switchTab('callback')" wire:click="editCallback({{ $response->id }})" aria-label="Configure callback for response {{ $response->id }}">Callback</button>
                    <button class="danger-text" type="button" wire:click="delete({{ $response->id }})" data-confirm-title="Confirm response action" data-confirm="Delete response #{{ $response->id }} (HTTP {{ $response->status_code }}) from this endpoint? {{ $selectionMode === 'rule' && $response->is_default ? 'It is the saved fallback: deletion will be blocked until you select and save another fallback.' : 'It will no longer be available for matching requests.' }}">Delete</button>

                        </div>
                    </details>
                </div>
                <details class="history-disclosure response-row-details" data-disclosure wire:ignore.self wire:key="response-details-{{ $response->id }}">
                    <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Details</strong></summary>
                    <div class="response-row-detail-content">
                        @if ($response->external_label !== null)<p class="field-help">Imported example: <strong>{{ $response->external_label }}</strong></p>@endif
                        <code class="response-body-summary">{{ ($response->body_mode ?? 'static') === 'template' ? 'JSON response template' : (Str::limit(preg_replace('/\s+/', ' ', $response->body ?? ''), 82) ?: 'Empty body') }}</code>
                        <div class="metadata-row"><span>{{ $response->delay_ms }} ms delay</span><span>{{ count($response->headers ?? []) }} headers</span>@if ($response->callback_enabled)<x-badge variant="info">Callback enabled</x-badge>@endif @if ($response->fault_enabled)<x-badge variant="warning">Fault: {{ $response->fault_type === 'timeout' ? 'long delay' : str_replace('_', ' ', $response->fault_type) }}</x-badge>@endif</div>
                        @if ($selectionMode !== 'weighted')
                    <div class="selection-move-actions">
                        <button class="icon-button" type="button" wire:click="moveSelectionResponse({{ $responseIndex }}, -1)" @disabled($responseIndex === 0) aria-label="Move response {{ $response->id }} earlier">↑</button>
                        <button class="icon-button" type="button" wire:click="moveSelectionResponse({{ $responseIndex }}, 1)" @disabled($responseIndex === $responses->count() - 1) aria-label="Move response {{ $response->id }} later">↓</button>
                    </div>
                        @endif
                @if ($selectionMode === 'rule')
                    <div class="selection-response-details">
                        <label class="toggle-inline"><x-radio name="fallback-response" value="{{ $response->id }}" wire:click="setFallback({{ $response->id }})" :checked="$fallbackResponseId === $response->id" aria-label="Use response {{ $response->id }} as default fallback" /><span>Default / fallback</span></label>
                        <details class="history-disclosure" data-disclosure wire:ignore.self wire:key="response-conditions-{{ $response->id }}">
                            <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Conditions</strong></summary>
                            @if ($fallbackResponseId === $response->id)<p class="field-help">Fallback is selected only when no other response matches; its conditions are not evaluated.</p>@endif
                            @foreach ($responseRules[$response->id] ?? [] as $ruleIndex => $condition)
                                <div class="rule-condition-row" wire:key="condition-{{ $response->id }}-{{ $ruleIndex }}">
                                    <div class="field-row three">
                                        <label class="field"><span>Field type</span><select class="ui-select" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_type"><option value="header">Header</option><option value="query">Query</option><option value="body_json_path">Body JSON path</option></select></label>
                                        <label class="field"><span>Field name</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_name" placeholder="X-Mode / status / user.id"></label>
                                        <label class="field"><span>Operator</span><select class="ui-select" wire:model.live="responseRules.{{ $response->id }}.{{ $ruleIndex }}.operator">@foreach (['equals', 'contains', 'regex', 'exists'] as $operator)<option value="{{ $operator }}">{{ ucfirst($operator) }}</option>@endforeach</select></label>
                                    </div>
                                    <div class="field-row three">
                                        <label class="field"><span>Value</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.value" @disabled($condition['operator'] === 'exists')></label>
                                        <label class="field"><span>Priority (lower first)</span><input type="number" min="0" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.priority"></label>
                                        <button class="icon-button danger" type="button" wire:click="removeRule({{ $response->id }}, {{ $ruleIndex }})" aria-label="Remove condition {{ $ruleIndex + 1 }} from response {{ $response->id }}">Remove</button>
                                    </div>
                                </div>
                            @endforeach
                            <button class="button button-tertiary button-small" type="button" wire:click="addRule({{ $response->id }})">Add condition</button>
                        </details>
                    </div>
                @endif
                <details class="history-disclosure response-history" data-disclosure>
                    <summary><span class="details-chevron" aria-hidden="true">›</span><span><strong>History</strong><small>Compare or restore response versions.</small></span></summary>
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

    <form class="card response-form" wire:submit="save">
        <div class="form-title-row">
            <h3>{{ $editingId ? 'Edit response #'.$editingId : 'Add response' }}</h3>
            @if ($editingId)
                <button class="text-button" type="button" wire:click="cancelEdit">Cancel edit</button>
            @endif
        </div>

        @if ($errors->any())
            <div class="validation-panel error-panel" role="alert">
                <strong>Response changes were not saved.</strong>
                @foreach (array_unique($errors->all()) as $message)<p>{{ $message }}</p>@endforeach
            </div>
        @endif



        <div class="field-row three">
            <div class="field">
                <label for="status-code">Status</label>
                <input id="status-code" type="number" min="100" max="599" wire:model="statusCode">
                @error('statusCode') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="response-weight">Weight</label>
                <input id="response-weight" type="number" min="1" wire:model="weight">
                @error('weight') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="response-delay">Delay (ms)</label>
                <input id="response-delay" type="number" min="0" max="{{ config('mock.max_delay_ms') }}" wire:model="delayMs">
                @error('delayMs') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="field">
            <label for="response-headers">Headers <span>JSON object</span></label>
            <textarea id="response-headers" class="code-input compact" rows="5" spellcheck="false" wire:model="headersJson"></textarea>
            @error('headersJson') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <fieldset class="field option-group" aria-labelledby="body-mode-label">
            <legend id="body-mode-label">Body</legend>
            <div class="segmented-control" role="group" aria-label="Response body mode">
                <button type="button" wire:click="setBodyMode('static')" aria-pressed="{{ $bodyMode === 'static' ? 'true' : 'false' }}">Static</button>
                <button type="button" wire:click="setBodyMode('template')" aria-pressed="{{ $bodyMode === 'template' ? 'true' : 'false' }}">Template</button>
            </div>
        </fieldset>

        @if ($bodyMode === 'static')
            <div class="field">
                <label for="response-body">Body <span>sent as-is</span></label>
                <textarea id="response-body" class="code-input compact" rows="9" spellcheck="false" wire:model="body"></textarea>
                @error('body') <p class="field-error">{{ $message }}</p> @enderror
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

            <div class="template-toolbar">
                <div class="segmented-control" role="group" aria-label="Template editor view">
                    <span class="disabled-tooltip" @if (! $builderSupported) tabindex="0" aria-label="{{ $builderUnavailableMessage }}" title="{{ $builderUnavailableMessage }}" @endif>
                        <button type="button" wire:click="setEditorView('builder')" aria-pressed="{{ $editorView === 'builder' ? 'true' : 'false' }}" @disabled(! $builderSupported)>Builder</button>
                    </span>
                    <button type="button" wire:click="setEditorView('json')" aria-pressed="{{ $editorView === 'json' ? 'true' : 'false' }}">JSON</button>
                </div>
                <button class="text-button" type="button" wire:click="insertExample">Example</button>
            </div>

            <div class="field-row three template-options">
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
                        @error('seed') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="field template-seed-note">
                        <span class="field-help">{{ $seedMode === 'request' ? 'The same request signature produces the same output.' : 'Regenerate creates fresh values.' }}</span>
                    </div>
                @endif
            </div>

            @if ($editorView === 'builder')
                <section class="schema-builder" aria-labelledby="schema-builder-title">
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
                        rows="18"
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
                    @error('template') <p id="response-template-error" class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($errorIssues->isNotEmpty())
                <div class="validation-panel error-panel" role="alert">
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

            <section class="template-preview code-block-wrap" aria-labelledby="template-preview-title">
                <div class="code-block-heading">
                    <span id="template-preview-title">Preview</span>
                    <button type="button" wire:click="previewTemplate" wire:loading.attr="disabled" wire:target="previewTemplate">Regenerate</button>
                </div>
                <p class="info-note">Request-context values in this preview are synthetic samples, not captured live traffic. Context tokens require the JSON editor.</p>
                @if ($previewOutput !== '')
                    <pre>{{ $previewOutput }}</pre>
                    <div class="template-preview-meta"><span>{{ $previewBytes }} B</span><span>{{ number_format($previewRenderMs, 2) }} ms</span></div>
                @else
                    <div class="template-preview-empty">No preview yet — fix any errors, then select Regenerate.</div>
                @endif
            </section>
        @endif



        <details class="history-disclosure" data-disclosure wire:ignore.self wire:key="response-fault-{{ $editingId ?? 'new' }}">
            <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Fault injection</strong>@if ($faultEnabled)<x-badge variant="warning">Enabled</x-badge>@endif</summary>
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
                    @error('faultType') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="response-fault-probability">Probability (%)</label>
                    <input id="response-fault-probability" type="number" min="0" max="100" wire:model="faultProbability" @disabled(! $faultEnabled)>
                    @error('faultProbability') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            @if (in_array($faultType, ['delay', 'timeout'], true))
                <div class="field-row two">
                    <div class="field"><label for="response-fault-delay-min">Delay min (ms)</label><input id="response-fault-delay-min" type="number" min="0" max="120000" wire:model="faultDelayMsMin" @disabled(! $faultEnabled)>@error('faultDelayMsMin') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="response-fault-delay-max">Delay max (ms) <span>optional</span></label><input id="response-fault-delay-max" type="number" min="0" max="120000" wire:model="faultDelayMsMax" @disabled(! $faultEnabled)>@error('faultDelayMsMax') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
            @endif
            @if ($faultType === 'timeout')
                <p class="info-note">Approximation: a long delay, capped at 120 seconds. <x-help-tip title="Timeout approximation" label="Set the delay longer than your client's timeout. The proxy or PHP worker may finish first; this does not hold a connection open indefinitely or reset TCP." /></p>
            @elseif ($faultType === 'malformed_body')
                <p class="field-help">Replaces JSON or XML with an unfinished document.</p>
            @elseif ($faultType === 'truncated_body')
                <p class="field-help">Returns the first half of the rendered body's bytes.</p>
            @endif
        </details>

        <button class="button button-primary button-full" type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($bodyMode === 'template' && collect($templateIssues)->contains(fn ($issue) => $issue['severity'] === 'error'))>
            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update response' : 'Add response' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
</section>
<section id="panel-callback" role="tabpanel" aria-labelledby="tab-callback" x-show="activeTab === 'callback'" x-cloak>
    <div class="callback-response-list" aria-label="Response callbacks">
        @forelse ($responses as $responseIndex => $response)
            @php($rowCallbackEnabled = $editingId === $response->id ? $callbackEnabled : ($pendingFormDrafts[$response->id]['state']['callbackEnabled'] ?? $response->callback_enabled))
            <div class="card callback-response-row {{ $editingId === $response->id ? 'selected' : '' }}" wire:key="callback-row-{{ $response->id }}">
                <x-badge>HTTP {{ $response->status_code }}</x-badge>
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
</section>
</div>
