<div class="response-grid {{ $bodyMode === 'template' ? 'template-active' : '' }}">
    <div class="response-list">
        @if (session('response-status'))
            <div class="flash inline" role="status"><span class="flash-icon">✓</span>{{ session('response-status') }}</div>
        @endif

        @forelse ($responses as $response)
            <article class="card response-card {{ $editingId === $response->id ? 'selected' : '' }}" wire:key="response-{{ $response->id }}">
                <div class="response-status">
                    <span class="status-dot status-{{ intdiv($response->status_code, 100) }}xx"></span>
                    <strong>{{ $response->status_code }}</strong>
                </div>
                <div class="response-description">
                    <code>{{ ($response->body_mode ?? 'static') === 'template' ? 'JSON response template' : (Str::limit(preg_replace('/\s+/', ' ', $response->body ?? ''), 82) ?: 'Empty body') }}</code>
                    <div class="metadata-row">
                        @if (($response->body_mode ?? 'static') === 'template')
                            <span class="state-chip enabled">Templated</span>
                        @endif
                        <span>Weight {{ $response->weight }}</span>
                        <span>{{ $response->delay_ms }} ms delay</span>
                        <span>{{ count($response->headers ?? []) }} {{ Str::plural('header', count($response->headers ?? [])) }}</span>
                    </div>
                </div>
                <div class="endpoint-actions">
                    <button class="icon-button" type="button" wire:click="edit({{ $response->id }})">Edit</button>
                    <button class="icon-button danger" type="button" wire:click="delete({{ $response->id }})" wire:confirm="Delete response #{{ $response->id }} (HTTP {{ $response->status_code }}) from this endpoint? It will no longer be available for matching requests.">Delete</button>
                </div>
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
                <button class="text-button" type="button" wire:click="createNew">Cancel edit</button>
            @endif
        </div>

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
            <div class="segmented-control" aria-label="Response body mode">
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
            @endphp

            @if (! $jsonContentType)
                <div class="validation-panel warning-panel" role="status">
                    <strong>JSON template with a non-JSON Content-Type</strong>
                    <p>The rendered body is JSON, but the configured Content-Type is {{ $contentType }}. Update the header if clients should parse it as JSON.</p>
                </div>
            @endif

            <div class="template-toolbar">
                <div class="segmented-control" aria-label="Template editor view">
                    <span class="disabled-tooltip" @if (! $builderSupported) tabindex="0" aria-label="Builder unavailable. This template uses features the builder can't show — edit as JSON" title="This template uses features the builder can't show — edit as JSON" @endif>
                        <button type="button" wire:click="setEditorView('builder')" aria-pressed="{{ $editorView === 'builder' ? 'true' : 'false' }}" @disabled(! $builderSupported)>Builder</button>
                    </span>
                    <button type="button" wire:click="setEditorView('json')" aria-pressed="{{ $editorView === 'json' ? 'true' : 'false' }}">JSON</button>
                </div>
                <button class="text-button" type="button" wire:click="insertExample">Example</button>
            </div>

            <div class="field-row three template-options">
                <div class="field">
                    <label for="template-locale">Locale</label>
                    <select id="template-locale" wire:model.live="locale">
                        @foreach ($templateLocales as $availableLocale)
                            <option value="{{ $availableLocale }}">{{ $availableLocale }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="template-seed-mode">Seed</label>
                    <select id="template-seed-mode" wire:model.live="seedMode">
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
                        <div class="segmented-control" aria-label="Schema root type">
                            <button type="button" wire:click="$set('builderSchema.root', 'object')" aria-pressed="{{ ($builderSchema['root'] ?? 'object') === 'object' ? 'true' : 'false' }}">Object</button>
                            <button type="button" wire:click="$set('builderSchema.root', 'list')" aria-pressed="{{ ($builderSchema['root'] ?? 'object') === 'list' ? 'true' : 'false' }}">List of objects</button>
                        </div>
                    </div>

                    @if (($builderSchema['root'] ?? 'object') === 'list')
                        <div class="builder-root-count">
                            <label class="select-field">
                                <span class="select-caption">Count</span>
                                <select wire:model.live="builderSchema.count_mode">
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
                    <div id="template-method-suggestions" class="template-autocomplete" data-template-autocomplete role="listbox" aria-label="Faker method suggestions" hidden></div>
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
                @if ($previewOutput !== '')
                    <pre>{{ $previewOutput }}</pre>
                    <div class="template-preview-meta"><span>{{ $previewBytes }} B</span><span>{{ number_format($previewRenderMs, 2) }} ms</span></div>
                @else
                    <div class="template-preview-empty">No preview yet — fix any errors, then select Regenerate.</div>
                @endif
            </section>
        @endif

        <button class="button button-primary button-full" type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($bodyMode === 'template' && collect($templateIssues)->contains(fn ($issue) => $issue['severity'] === 'error'))>
            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update response' : 'Add response' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
