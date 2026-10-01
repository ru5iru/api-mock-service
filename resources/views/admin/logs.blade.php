@php
    $callbackType = request()->routeIs('dashboard.callbacks.*') || (request()->routeIs('dashboard.logs.*') && request()->query('type') === 'callbacks');
@endphp
<x-layouts.dashboard title="Logs">
    <x-page-header title="Logs" description="Inspect incoming requests and asynchronous callback deliveries." />

    <nav class="log-type-tabs" aria-label="Log type">
        <a href="{{ route('dashboard.logs.index', ['type' => 'requests']) }}" wire:navigate @if (! $callbackType) aria-current="page" @endif>Requests</a>
        <a href="{{ route('dashboard.logs.index', ['type' => 'callbacks']) }}" wire:navigate @if ($callbackType) aria-current="page" @endif>Callbacks</a>
    </nav>

    @if ($callbackType)
        <livewire:admin.callback-log />
    @else
        <livewire:admin.log-viewer :full="true" />
    @endif
</x-layouts.dashboard>
