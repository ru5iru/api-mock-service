<x-layouts.dashboard title="Endpoints">
    <x-page-header title="Endpoints" :count="\App\Models\MockEndpoint::query()->count()">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>
                <span aria-hidden="true">＋</span> New endpoint
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:admin.endpoint-index />

    <section id="request-log" class="section-spacer">
        <livewire:admin.log-viewer :full="false" />
    </section>
</x-layouts.dashboard>
