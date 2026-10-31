<div class="workbench-explorer">
    <label class="field explorer-search"><span>Find an endpoint</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" /></svg><input type="search" wire:model.live.debounce.250ms="search" aria-label="Find an endpoint" placeholder="Find an endpoint…"></label>
    <label class="field"><span>Collections</span><select class="ui-select" wire:model.live="collection" aria-label="Explorer collection"><option value="all">All collections</option><option value="none">Unfiled</option>@foreach($collections as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ $item->endpoints_count }})</option>@endforeach</select></label>
    <div class="workbench-explorer-list" aria-label="Endpoints">
        @forelse($endpoints as $item)
            <a href="{{ route('dashboard.endpoints.edit', $item) }}" wire:navigate wire:key="explorer-{{ $item->id }}" class="workbench-explorer-item" @if((string) $currentEndpointId === (string) $item->id) aria-current="page" @endif>
                <span class="method-badge method-{{ strtolower($item->method) }}">{{ $item->method }}</span><span><strong>{{ $item->displayName() }}</strong><small>{{ $item->responses_count }} {{ Str::plural('response', $item->responses_count) }} · {{ $item->enabled ? 'Enabled' : 'Disabled' }}</small></span>
            </a>
        @empty
            <p class="workbench-context-note">No endpoints match. Change the search or collection.</p>
        @endforelse
    </div>
    @if($endpoints->hasPages())<div class="workbench-explorer-pages"><button class="button button-tertiary" type="button" wire:click="previousPage('explorerPage')" @disabled($endpoints->onFirstPage()) aria-label="Previous explorer page">Previous</button><span>{{ $endpoints->currentPage() }} / {{ $endpoints->lastPage() }}</span><button class="button button-tertiary" type="button" wire:click="nextPage('explorerPage')" @disabled(! $endpoints->hasMorePages()) aria-label="Next explorer page">Next</button></div>@endif
</div>
