<x-layouts.dashboard title="Request log">
    <x-page-header title="Request log" description="Inspect exact and fallback matches, response status, and timing.">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
        </x-slot:actions>
    </x-page-header>

    <livewire:admin.log-viewer :full="true" />
</x-layouts.dashboard>
