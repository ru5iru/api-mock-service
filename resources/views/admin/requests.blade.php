<x-layouts.dashboard title="Request log">
    <header class="compact-page-header">
        <div>
            <span class="eyebrow">Diagnostics</span>
            <h1>Request log</h1>
            <p>Inspect recent invocations, exact and fallback matches, response status, and timing.</p>
        </div>
        <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
    </header>

    <livewire:admin.log-viewer :full="true" />
</x-layouts.dashboard>
