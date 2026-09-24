<div class="response-grid">
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
                    <code>{{ Str::limit(preg_replace('/\s+/', ' ', $response->body ?? ''), 82) ?: 'Empty body' }}</code>
                    <div class="metadata-row">
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

        <div class="field">
            <label for="response-body">Body <span>sent as-is</span></label>
            <textarea id="response-body" class="code-input compact" rows="9" spellcheck="false" wire:model="body"></textarea>
            @error('body') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button class="button button-primary button-full" type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update response' : 'Add response' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
