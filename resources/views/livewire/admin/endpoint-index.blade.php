<div>
    @php
        $pageIds = $endpoints->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $pageSelected = $pageIds !== [] && count(array_intersect($pageIds, $selected)) === count($pageIds);
        $filtersActive = $search !== '' || $method !== '' || $state !== 'all' || $collection !== 'all' || $tags !== [];
        $allEndpointCount = \App\Models\MockEndpoint::query()->count();
    @endphp
    <div class="toolbar card" aria-label="Endpoint controls">
        <label class="page-select-control" title="{{ $pageSelected ? 'Clear selection' : 'Select this page' }}">
            <input type="checkbox" @checked($pageSelected) wire:click="{{ $pageSelected ? 'clearSelection' : 'selectPage' }}" aria-label="{{ $pageSelected ? 'Clear endpoint selection' : 'Select all endpoints on this page' }}">
        </label>
        <label class="search-field">
            <span class="sr-only">Search endpoints</span>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="search" wire:model.live.debounce.250ms="search" data-search-shortcut placeholder="Search name, method, path, or curl…">
        </label>

        <div class="toolbar-controls">
            <label class="select-field">
                <span class="select-caption">Method</span>
                <select wire:model.live="method" aria-label="Filter by method">
                    <option value="">All methods</option>
                    @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label class="select-field">
                <span class="select-caption">State</span>
                <select wire:model.live="state" aria-label="Filter by state">
                    <option value="all">Any state</option>
                    <option value="enabled">Enabled</option>
                    <option value="disabled">Disabled</option>
                </select>
            </label>
            <label class="select-field sort-field">
                <span class="select-caption">Sort</span>
                <select wire:model.live="sort" aria-label="Sort endpoints">
                    <option value="recent">Recently updated</option>
                    <option value="name">Name</option>
                    <option value="priority">Priority</option>
                </select>
            </label>
            <label class="select-field">
                <span class="select-caption">Collection</span>
                <select wire:model.live="collection" aria-label="Filter by collection">
                    <option value="all">All collections</option>
                    <option value="none">No collection</option>
                    @foreach ($collections as $endpointCollection)
                        <option value="{{ $endpointCollection->id }}">{{ $endpointCollection->name }} ({{ $endpointCollection->endpoints_count }})</option>
                    @endforeach
                </select>
            </label>
            @if ($search !== '' || $method !== '' || $state !== 'all' || $sort !== 'recent' || $collection !== 'all' || $tags !== [])
                <button class="text-button filter-reset" type="button" wire:click="clearFilters">Clear filters</button>
            @endif
            <span class="result-count">
                @if ($filtersActive)
                    {{ $endpoints->total() }} of {{ $allEndpointCount }} {{ Str::plural('endpoint', $allEndpointCount) }}
                @else
                    {{ $allEndpointCount }} {{ Str::plural('endpoint', $allEndpointCount) }}
                @endif
            </span>
        </div>
        @if ($availableTags->isNotEmpty())
            <div class="tag-filter-row" aria-label="Filter endpoints by tag">
                @foreach ($availableTags as $tag)
                    <label class="tag-chip selectable {{ in_array($tag->id, $tags, true) ? 'selected' : '' }}">
                        <input type="checkbox" value="{{ $tag->id }}" wire:model.live="tags">
                        <span>{{ $tag->name }}</span><small>{{ $tag->endpoints_count }}</small>
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    @if ($selected !== [])
        <div class="selection-bar card" aria-live="polite">
            <strong>{{ count($selected) }} selected</strong>
            <div class="selection-actions">
                <label class="select-field bulk-select">
                    <span class="sr-only">Move selected endpoints to collection</span>
                    <select wire:model="bulkCollection" aria-label="Collection for selected endpoints">
                        <option value="">No collection</option>
                        @foreach ($collections as $endpointCollection)<option value="{{ $endpointCollection->id }}">{{ $endpointCollection->name }}</option>@endforeach
                    </select>
                </label>
                <button class="button button-tertiary button-small" type="button" wire:click="bulkMoveToCollection">Move to collection</button>
                @if ($availableTags->isNotEmpty())
                    <details class="bulk-tag-picker">
                        <summary class="button button-tertiary button-small">Add tags</summary>
                        <div>
                            @foreach ($availableTags as $tag)
                                <label><input type="checkbox" value="{{ $tag->id }}" wire:model="bulkTags"> {{ $tag->name }}</label>
                            @endforeach
                            <button class="button button-primary button-small" type="button" wire:click="bulkAddTags" @disabled($bulkTags === [])>Apply tags</button>
                        </div>
                    </details>
                @endif
                <button class="button button-tertiary button-small" type="button" wire:click="bulkSetEnabled(true)">Enable</button>
                <button class="button button-tertiary button-small" type="button" wire:click="bulkSetEnabled(false)">Disable</button>
                <button class="button button-secondary button-small" type="button" wire:click="bulkExport">Export</button>
                <button class="button button-danger button-small" type="button" wire:click="bulkDelete" wire:confirm="Delete {{ count($selected) }} selected endpoints and all of their configured responses? This cannot be undone.">Delete</button>
                <button class="text-button" type="button" wire:click="clearSelection">Clear</button>
            </div>
        </div>
    @endif

    <div class="endpoint-list" wire:loading.class="is-loading">
        <div class="skeleton-list" wire:loading.flex wire:target="search,method,state,sort,clearFilters">
            @foreach (range(1, 3) as $row)
                <span class="skeleton-row" aria-hidden="true"></span>
            @endforeach
            <span class="sr-only">Loading endpoints…</span>
        </div>

        <div wire:loading.remove wire:target="search,method,state,sort,clearFilters">
            @forelse ($endpoints as $endpoint)
                @php
                    $stats = $endpoint->requestStats();
                    $derivedName = strtoupper($endpoint->method).' '.$endpoint->requestPath();
                    $autoDerivedName = trim((string) $endpoint->name) === '' || trim((string) $endpoint->name) === $derivedName;
                @endphp
                <article
                    class="endpoint-card card row-link"
                    wire:key="endpoint-{{ $endpoint->id }}"
                    tabindex="0"
                    data-row-href="{{ route('dashboard.endpoints.edit', $endpoint) }}"
                    aria-label="Edit {{ $endpoint->displayName() }}"
                >
                    <label class="row-select" data-stop-row-navigation>
                        <input type="checkbox" value="{{ $endpoint->id }}" wire:model.live="selected" aria-label="Select {{ $endpoint->displayName() }}">
                    </label>

                    <div class="endpoint-main">
                        <div class="endpoint-copy">
                            @if ($autoDerivedName)
                                <div class="endpoint-primary-line">
                                    <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
                                    <code class="request-target endpoint-primary" title="{{ $endpoint->requestTarget() }}">{{ $endpoint->requestTarget() }}</code>
                                </div>
                            @else
                                <h3>{{ $endpoint->displayName() }}</h3>
                                <div class="endpoint-request-line">
                                    <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
                                    <code class="request-target" title="{{ $endpoint->requestTarget() }}">{{ $endpoint->requestTarget() }}</code>
                                </div>
                            @endif
                            <div class="endpoint-detail-line">
                                <div class="request-facts" aria-label="Request composition">
                                    <span>{{ $stats['headers'] }} {{ Str::plural('header', $stats['headers']) }}</span>
                                    <span>Body {{ number_format($stats['body_bytes']) }} B</span>
                                    <details class="canonical-popover" data-stop-row-navigation data-disclosure>
                                        <summary aria-expanded="false" aria-controls="canonical-endpoint-{{ $endpoint->id }}"><span class="details-chevron" aria-hidden="true">›</span> Canonical request</summary>
                                        <pre id="canonical-endpoint-{{ $endpoint->id }}">{{ $endpoint->normalized_curl }}</pre>
                                    </details>
                                </div>
                                <div class="metadata-row">
                                    <span class="state-label {{ $endpoint->enabled ? 'enabled' : 'disabled' }}"><i></i>{{ $endpoint->enabled ? 'Enabled' : 'Disabled' }}</span>
                                    <span>{{ $endpoint->responses_count }} {{ Str::plural('response', $endpoint->responses_count) }}</span>
                                    @if ($endpoint->priority !== 0)
                                        <span>Priority {{ $endpoint->priority }}</span>
                                    @endif
                                    <span>
                                        Signature {{ $endpoint->signatureVariant() }}
                                        <x-help-tip title="Signature version" label="V1 includes all headers; V2 ignores cookies; V3 ignores authentication; V4 ignores both; V5 ignores all headers." />
                                    </span>
                                    <span title="{{ $endpoint->updated_at->toDayDateTimeString() }}">Updated {{ $endpoint->updated_at->diffForHumans() }}</span>
                                </div>
                                @if ($endpoint->collection || $endpoint->tags->isNotEmpty())
                                    <div class="endpoint-taxonomy">
                                        @if ($endpoint->collection)<span class="collection-chip">{{ $endpoint->collection->name }}</span>@endif
                                        @foreach ($endpoint->tags as $tag)<span class="tag-chip">{{ $tag->name }}</span>@endforeach
                                    </div>
                                @endif
                            </div>
                            @if ($endpoint->responses_count === 0)
                                <div class="inline-warning">
                                    <span aria-hidden="true">!</span>
                                    <strong>No responses – requests will fail</strong>
                                    <a href="{{ route('dashboard.endpoints.edit', $endpoint) }}#responses" wire:navigate data-stop-row-navigation>Add response</a>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="endpoint-actions" data-stop-row-navigation>
                        @if ($mockCurls[$endpoint->id] !== null)
                            <button class="button button-secondary button-small" type="button"
                                    data-copy-curl="{{ $mockCurls[$endpoint->id] }}"
                                    aria-label="Copy mock curl for {{ $endpoint->displayName() }}">
                                <span aria-hidden="true">⧉</span> Copy mock curl
                            </button>
                        @else
                            <button class="button button-secondary button-small" type="button" disabled
                                    title="The saved curl cannot be parsed into a mock invocation command.">
                                Copy unavailable
                            </button>
                        @endif

                        <label class="switch-control" title="{{ $endpoint->enabled ? 'Disable' : 'Enable' }} {{ $endpoint->displayName() }}">
                            <input type="checkbox" @checked($endpoint->enabled) wire:click="toggleEnabled({{ $endpoint->id }})" aria-label="Endpoint enabled">
                            <span aria-hidden="true"></span>
                        </label>

                        <a class="button button-tertiary button-small" href="{{ route('dashboard.endpoints.edit', $endpoint) }}" wire:navigate>Edit</a>

                        <details class="overflow-menu">
                            <summary aria-label="More actions for {{ $endpoint->displayName() }}">•••</summary>
                            <div>
                                <button type="button" wire:click="duplicate({{ $endpoint->id }})">Duplicate</button>
                                <button class="danger-text" type="button"
                                        wire:click="delete({{ $endpoint->id }})"
                                        wire:confirm="Delete {{ $endpoint->displayName() }} and all {{ $endpoint->responses_count }} configured responses? This cannot be undone.">
                                    Delete
                                </button>
                            </div>
                        </details>
                    </div>
                </article>
            @empty
                <div class="empty-state card">
                    <div class="empty-illustration" aria-hidden="true"><span>{ }</span></div>
                    @php($filtered = $search !== '' || $method !== '' || $state !== 'all' || $collection !== 'all' || $tags !== [])
                    <h3>{{ $filtered ? 'No endpoints match these filters' : 'Your mock registry is empty' }}</h3>
                    <p>{{ $filtered ? 'Try a different search, method, or state.' : 'Create an endpoint from a curl command or import an existing MockDeck configuration.' }}</p>
                    <div class="empty-actions">
                        @if ($filtered)
                            <button class="button button-secondary" type="button" wire:click="clearFilters">Clear filters</button>
                        @else
                            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
                            <a class="button button-secondary" href="{{ route('dashboard.config.index') }}" wire:navigate>Import configuration</a>
                        @endif
                    </div>
                </div>
            @endforelse
        </div>
    </div>

    @if ($endpoints->hasPages())
        <div class="pagination-wrap">{{ $endpoints->links() }}</div>
    @endif
</div>
