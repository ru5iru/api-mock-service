            <div class="callback-fields">
                <label class="toggle-inline"><input type="checkbox" wire:model.live="callbackEnabled"><span>Enable callback</span></label>
                <h4 class="callback-group-heading">Delivery</h4>
                <div class="field-row two">
                    <div class="field">
                        <label for="callback-url">Target URL</label>
                        <input id="callback-url" type="text" inputmode="url" placeholder="https://example.test/webhook" wire:model="callbackUrl">
                        @error('callbackUrl') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="callback-method">Method</label>
                        <select class="ui-select" id="callback-method" wire:model="callbackMethod">
                            @foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                                <option value="{{ $method }}">{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="callback-headers">Headers <span>JSON object</span></label>
                    <textarea id="callback-headers" class="code-input compact" rows="4" spellcheck="false" wire:model="callbackHeadersJson"></textarea>
                    @error('callbackHeadersJson') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <div class="template-toolbar">
                        <div class="segmented-control" role="group" aria-label="Callback body editor view">
                            <span class="disabled-tooltip" @if (! $callbackBuilderSupported) tabindex="0" aria-label="Builder unavailable. This callback body uses features the builder cannot show; continue editing JSON." title="This callback body uses features the builder cannot show; continue editing JSON." @endif>
                                <button type="button" wire:click="setCallbackEditorView('builder')" aria-pressed="{{ $callbackEditorView === 'builder' ? 'true' : 'false' }}" @disabled(! $callbackBuilderSupported)>Builder</button>
                            </span>
                            <button type="button" wire:click="setCallbackEditorView('json')" aria-pressed="{{ $callbackEditorView === 'json' ? 'true' : 'false' }}">JSON</button>
                        </div>
                    </div>
                    @if ($callbackEditorView === 'builder')
                        <section class="schema-builder" aria-label="Callback body schema">
                            <div class="section-heading section-heading-inline">
                                <p>Define callback JSON fields using the response template builder.</p>
                                <div class="segmented-control" role="group" aria-label="Callback schema root type">
                                    <button type="button" wire:click="$set('callbackBuilderSchema.root', 'object')" aria-pressed="{{ ($callbackBuilderSchema['root'] ?? 'object') === 'object' ? 'true' : 'false' }}">Object</button>
                                    <button type="button" wire:click="$set('callbackBuilderSchema.root', 'list')" aria-pressed="{{ ($callbackBuilderSchema['root'] ?? 'object') === 'list' ? 'true' : 'false' }}">List of objects</button>
                                </div>
                            </div>
                            @if (($callbackBuilderSchema['root'] ?? 'object') === 'list')
                                <div class="builder-root-count">
                                    <label class="select-field"><span class="select-caption">Count</span><select class="ui-select ui-select-dense" wire:model.live="callbackBuilderSchema.count_mode"><option value="fixed">Fixed</option><option value="range">Min–max</option></select></label>
                                    @if (($callbackBuilderSchema['count_mode'] ?? 'fixed') === 'range')
                                        <label class="compact-input"><span>Min</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="callbackBuilderSchema.min"></label>
                                        <label class="compact-input"><span>Max</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="callbackBuilderSchema.max"></label>
                                    @else
                                        <label class="compact-input"><span>Items</span><input type="number" min="0" max="1000" wire:model.live.debounce.400ms="callbackBuilderSchema.count"></label>
                                    @endif
                                </div>
                            @endif
                            <div class="schema-rows">
                                @foreach (($callbackBuilderSchema['fields'] ?? []) as $fieldIndex => $fieldRow)
                                    @include('livewire.admin.partials.schema-row', [
                                        'row' => $fieldRow,
                                        'rowPath' => 'fields.'.$fieldIndex,
                                        'parentPath' => 'fields',
                                        'rowIndex' => $fieldIndex,
                                        'depth' => 0,
                                        'showKey' => true,
                                        'schemaModel' => 'callbackBuilderSchema',
                                        'schemaTarget' => 'callback',
                                    ])
                                @endforeach
                            </div>
                            <button class="button button-secondary button-small add-schema-row" type="button" wire:click="addSchemaRow('fields', 'callback')"><span aria-hidden="true">+</span> Add field</button>
                        </section>
                    @else
                        <div data-template-editor-root>
                            <label for="callback-body">Body <span>JSON template</span></label>
                            <textarea id="callback-body" class="code-input compact" rows="8" spellcheck="false" wire:model.live.debounce.400ms="callbackBody" data-template-editor role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="callback-method-suggestions"></textarea>
                            <div id="callback-method-suggestions" class="template-autocomplete" data-template-autocomplete role="listbox" aria-label="Callback Faker method suggestions" wire:ignore hidden></div>
                        </div>
                    @endif
                    <span class="field-help">Faker and request-context tokens. <x-help-tip title="Callback body tokens" label="Use environment values, $request.method, $request.body, $request.json.field, or Faker methods. Advanced context tokens require the JSON editor." /> <code>@verbatim{{env.KEY}}@endverbatim</code></span>
                    @error('callbackBody') <p class="field-error">{{ $message }}</p> @enderror
                    <button class="text-button" type="button" wire:click="previewCallback">Preview callback body</button>
                    @if ($callbackPreview !== '') <pre class="code-block">{{ $callbackPreview }}</pre> @endif
                </div>
                <details class="history-disclosure" data-disclosure wire:ignore.self wire:key="callback-retry-{{ $editingId }}">
                <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Retry policy</strong></summary>
                <div class="field-row three">
                    <div class="field"><label for="callback-delay">Delay min (ms)</label><input id="callback-delay" type="number" min="0" max="30000" wire:model="callbackDelayMs">@error('callbackDelayMs') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="callback-delay-max">Delay max (ms) <span>optional</span></label><input id="callback-delay-max" type="number" min="0" max="30000" wire:model="callbackDelayMaxMs">@error('callbackDelayMaxMs') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="callback-attempts">Max attempts</label><input id="callback-attempts" type="number" min="1" max="5" wire:model="callbackRetry">@error('callbackRetry') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="field-row two">
                    <div class="field"><label for="callback-backoff">Retry backoff (ms)</label><input id="callback-backoff" type="number" min="0" max="30000" wire:model="callbackBackoffMs">@error('callbackBackoffMs') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="callback-timeout">Attempt timeout (ms)</label><input id="callback-timeout" type="number" min="100" max="10000" wire:model="callbackTimeoutMs">@error('callbackTimeoutMs') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
                </details>
                <details class="history-disclosure" data-disclosure wire:ignore.self wire:key="callback-signing-{{ $editingId }}">
                <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Signing</strong></summary>
                <div class="field-row two">
                    <div class="field">
                        <label class="toggle-inline"><input type="checkbox" wire:model.live="callbackSigningEnabled"><span>Sign requests</span></label>
                        <label for="callback-secret">Signing secret {{ $callbackSecretSet ? '(saved; leave blank to keep)' : '' }}</label>
                        <input id="callback-secret" type="password" autocomplete="new-password" wire:model="callbackSigningSecret">
                        @error('callbackSigningSecret') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field"><label for="callback-signature-header">Signature header</label><input id="callback-signature-header" wire:model="callbackSignatureHeader">@error('callbackSignatureHeader') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
                <p class="field-help">HMAC signing. <x-help-tip title="Callback signing" label="Signing computes the lowercase hex HMAC-SHA256 of the exact raw resolved body bytes, using your secret as the key, and sends the digest in the named header. With signing off, that header is omitted entirely." /></p>
                </details>
                @if ($editingId)
                    <div class="callback-actions">
                        <button class="button button-secondary button-small" type="button" wire:click="sendTestCallback">Send test callback</button>
                        <a class="text-button" href="{{ route('dashboard.logs.index', ['type' => 'callbacks', 'response_id' => $editingId]) }}" wire:navigate>View callback log</a>
                        @error('callbackEnabled') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($callbackTestId && $callbackTestStatus)
                        <p class="info-note" role="status" @if ($callbackTestStatus === 'Queued' || $callbackTestStatus === 'Pending') wire:poll.2s="pollCallbackTest" @endif>Test callback: {{ $callbackTestStatus }}</p>
                    @endif
                @endif
            </div>
