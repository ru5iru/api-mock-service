<form wire:submit="save" class="editor-grid">
    <section class="card form-card">
        <div class="card-heading">
            <span class="step-number">1</span>
            <div>
                <h2>Request definition</h2>
                <p>The command is parsed locally by the application; it is never executed.</p>
            </div>
        </div>

        <div class="field">
            <label for="endpoint-name">Name <span>optional</span></label>
            <input id="endpoint-name" type="text" wire:model="name" placeholder="e.g. Create a customer">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <div class="label-row">
                <label for="raw-curl">Curl command</label>
                <span wire:loading.delay wire:target="rawCurl,excludeCookies,excludeAuth,excludeHeaders" class="loading-label">Recomputing…</span>
            </div>
            <textarea id="raw-curl" class="code-input" rows="12" spellcheck="false"
                      wire:model.live.debounce.350ms="rawCurl"></textarea>
            @error('rawCurl') <p class="field-error">{{ $message }}</p> @enderror
            @if ($previewError && ! $errors->has('rawCurl'))
                <p class="field-error">{{ $previewError }}</p>
            @endif
        </div>

        <fieldset class="field option-group">
            <legend>Matching policy</legend>
                <label class="toggle-row">
                <span>
                    <strong>Ignore cookies</strong>
                    <small>Removes the Cookie header from the signature.</small>
                </span>
                <input type="checkbox" wire:model.live="excludeCookies" @disabled($excludeHeaders)>
            </label>
            <label class="toggle-row">
                <span>
                    <strong>Ignore authentication</strong>
                    <small>Removes Authorization and Proxy-Authorization headers.</small>
                </span>
                <input type="checkbox" wire:model.live="excludeAuth" @disabled($excludeHeaders)>
            </label>
            <label class="toggle-row">
                <span>
                    <strong>Ignore all headers</strong>
                    <small>Matches only method, URL path and query, and body.</small>
                </span>
                <input type="checkbox" wire:model.live="excludeHeaders">
            </label>
        </fieldset>

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Cancel</a>
            <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $endpointId ? 'Save changes' : 'Create endpoint' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </section>

    <aside class="preview-column">
        <section class="card preview-card sticky-card">
            <div class="card-heading tight">
                <span class="step-number">2</span>
                <div>
                    <h2>Signature preview</h2>
                    <p>This is what will be persisted and matched.</p>
                </div>
            </div>

            @if ($preview)
                <div class="request-summary">
                    <span class="method-badge method-{{ strtolower($preview['parsed']->method) }}">{{ $preview['parsed']->method }}</span>
                    <code>{{ $preview['parsed']->url }}</code>
                </div>

                <dl class="preview-facts">
                    <div><dt>Variant</dt><dd>{{ $preview['variant']->name }}</dd></div>
                    <div><dt>Headers parsed</dt><dd>{{ count($preview['parsed']->headers) }}</dd></div>
                    <div><dt>Body bytes</dt><dd>{{ strlen($preview['parsed']->body) }}</dd></div>
                </dl>

                <div class="code-block-wrap">
                    <div class="code-block-label">SHA-256</div>
                    <code class="hash-value">{{ $preview['variant']->hash }}</code>
                </div>

                <details class="normalized-details" open>
                    <summary>Canonical request</summary>
                    <pre>{{ $preview['variant']->normalized }}</pre>
                </details>

                <details class="normalized-details">
                    <summary>Parsed headers ({{ count($preview['parsed']->headers) }})</summary>
                    <pre>@forelse ($preview['parsed']->headers as $header)
{{ $header['name'] }}: {{ $header['value'] }}
@empty
— none —
@endforelse</pre>
                </details>

                <details class="normalized-details">
                    <summary>Parsed body ({{ strlen($preview['parsed']->body) }} bytes)</summary>
                    <pre>{{ $preview['parsed']->body !== '' ? $preview['parsed']->body : '— empty —' }}</pre>
                </details>
            @else
                <div class="preview-placeholder">
                    <span aria-hidden="true">#</span>
                    <p>A valid curl command will reveal its canonical request and digest here.</p>
                </div>
            @endif
        </section>

        <div class="info-note">
            <strong>Mock hosts are interchangeable</strong>
            <p>Matching uses the URL path and query, so no original-URL header is needed when calling this mock host.</p>
        </div>
    </aside>
</form>
