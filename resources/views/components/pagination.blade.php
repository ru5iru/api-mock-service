@if ($paginator->hasPages())
    <nav class="pagination-nav" aria-label="Endpoint pages">
        <span class="pagination-summary">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }} endpoints</span>
        <div class="pagination-controls">
            <button class="button button-secondary button-small" type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage,gotoPage" @disabled($paginator->onFirstPage()) aria-label="Previous page">Previous</button>
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination-gap" aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span class="pagination-current" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                        @else
                            <button class="button button-secondary button-small" type="button" wire:key="page-{{ $paginator->getPageName() }}-{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage,gotoPage" aria-label="Go to page {{ $page }}">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach
            <button class="button button-secondary button-small" type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage,gotoPage" @disabled(! $paginator->hasMorePages()) aria-label="Next page">Next</button>
        </div>
    </nav>
@endif
