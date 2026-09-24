<x-layouts.dashboard :title="$endpoint ? 'Edit endpoint' : 'New endpoint'">
    <x-page-header :breadcrumbs="[
        ['label' => 'Endpoints', 'url' => route('dashboard.endpoints.index')],
        ['label' => $endpoint?->displayName() ?? 'New endpoint'],
    ]">
        @if ($endpoint)
            <x-slot:actions>
                <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
            </x-slot:actions>
        @endif
    </x-page-header>

    <livewire:admin.endpoint-form :endpoint="$endpoint" />

    @if ($endpoint)
        <section id="responses" class="section-spacer">
            <div class="section-heading">
                <h2>Configured responses</h2>
                <p>One response is deterministic; multiple responses are selected by weight.</p>
            </div>
            <livewire:admin.response-manager :endpoint="$endpoint" />
        </section>
    @endif
</x-layouts.dashboard>
