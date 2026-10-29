<x-layouts.dashboard title="Endpoints">
    <x-page-header title="Endpoints" :count="\App\Models\MockEndpoint::query()->count()">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>
                <x-workbench-icon name="plus" /> New endpoint
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:admin.endpoint-index />

    <details id="request-log" class="registry-activity section-spacer" data-disclosure open>
        <summary aria-expanded="true" aria-controls="registry-recent-requests"><x-disclosure-chevron /> Recent requests</summary>
        <div id="registry-recent-requests">
            <livewire:admin.log-viewer :full="false" />
        </div>
    </details>
</x-layouts.dashboard>
