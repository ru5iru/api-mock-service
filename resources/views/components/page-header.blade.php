@props([
    'title' => null,
    'description' => null,
    'count' => null,
    'breadcrumbs' => [],
])

<header {{ $attributes->class('page-header') }}>
    <div class="page-header-copy">
        @if ($breadcrumbs !== [])
            <h1 class="page-breadcrumbs">
                @foreach ($breadcrumbs as $crumb)
                    @if (! $loop->last && ($crumb['url'] ?? null))
                        <a href="{{ $crumb['url'] }}" wire:navigate>{{ $crumb['label'] }}</a>
                        <span aria-hidden="true">/</span>
                    @else
                        <span @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </h1>
        @else
            <div class="page-title-line">
                <h1>{{ $title }}</h1>
                @if ($count !== null)
                    <span class="page-title-count">{{ $count }}</span>
                @endif
            </div>
        @endif

        @if ($description)
            <p>{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-header-actions">{{ $actions }}</div>
    @endisset
</header>
