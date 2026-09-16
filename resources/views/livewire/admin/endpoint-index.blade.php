<div>
    <div class="toolbar card">
        <label class="search-field">
            <span class="sr-only">Search endpoints</span>
            <span aria-hidden="true">⌕</span>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search by name, method, or curl…">
        </label>
        <span class="muted small">{{ $endpoints->total() }} {{ Str::plural('endpoint', $endpoints->total()) }}</span>
    </div>

    <div class="endpoint-list" wire:loading.class="is-loading">
        @forelse ($endpoints as $endpoint)
            <article class="endpoint-card card" wire:key="endpoint-{{ $endpoint->id }}">
                <div class="endpoint-main">
                    <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
                    <div class="endpoint-copy">
                        <h3>
                            <a href="{{ route('dashboard.endpoints.edit', $endpoint) }}" wire:navigate>
                                {{ $endpoint->name ?: 'Untitled endpoint' }}
                            </a>
                        </h3>
                        <code>{{ Str::limit(Str::after($endpoint->normalized_curl, "\n"), 110) }}</code>
                        <div class="metadata-row">
                            <span>{{ $endpoint->responses_count }} {{ Str::plural('response', $endpoint->responses_count) }}</span>
                            <span>Variant {{ $endpoint->exclude_headers ? 'V5' : ($endpoint->exclude_cookies && $endpoint->exclude_auth ? 'V4' : ($endpoint->exclude_auth ? 'V3' : ($endpoint->exclude_cookies ? 'V2' : 'V1'))) }}</span>
                            <span>Updated {{ $endpoint->updated_at->diffForHumans() }}</span>
                        </div>
                    </div>
                </div>

                <div class="endpoint-actions">
                    <button class="icon-button" type="button"
                            data-copy-curl="{{ $mockCurls[$endpoint->id] }}"
                            aria-label="Copy mock curl for {{ $endpoint->name ?: 'endpoint' }}">
                        Copy mock curl
                    </button>
                    <a class="icon-button" href="{{ route('dashboard.endpoints.edit', $endpoint) }}" wire:navigate aria-label="Edit {{ $endpoint->name ?: 'endpoint' }}">Edit</a>
                    <button class="icon-button danger" type="button"
                            wire:click="delete({{ $endpoint->id }})"
                            wire:confirm="Delete this endpoint and all of its responses?">
                        Delete
                    </button>
                </div>
            </article>
        @empty
            <div class="empty-state card">
                <div class="empty-illustration" aria-hidden="true"><span>{ }</span></div>
                <h3>{{ $search ? 'No endpoints match that search' : 'Your mock registry is empty' }}</h3>
                <p>{{ $search ? 'Try a different name, method, or URL fragment.' : 'Paste your first curl command and return a configured response in minutes.' }}</p>
                @unless ($search)
                    <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>Create first endpoint</a>
                @endunless
            </div>
        @endforelse
    </div>

    @if ($endpoints->hasPages())
        <div class="pagination-wrap">{{ $endpoints->links() }}</div>
    @endif
</div>
