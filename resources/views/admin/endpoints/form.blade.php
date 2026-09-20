<x-layouts.dashboard :title="$endpoint ? 'Edit endpoint' : 'New endpoint'">
    <div class="breadcrumb">
        <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Endpoints</a>
        <span>/</span>
        <span>{{ $endpoint?->displayName() ?? 'New endpoint' }}</span>
    </div>

    <header class="compact-page-header">
        <div>
            <span class="eyebrow">{{ $endpoint ? 'Endpoint #'.$endpoint->id : 'Create mock' }}</span>
            <h1>{{ $endpoint ? 'Edit endpoint' : 'New endpoint' }}</h1>
            <p>Define the request, review its matching policy, then configure at least one response.</p>
        </div>
        @if ($endpoint)
            <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
        @endif
    </header>

    <livewire:admin.endpoint-form :endpoint="$endpoint" />

    @if ($endpoint)
        <section id="responses" class="section-spacer">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Response pool</span>
                    <h2>Configured responses</h2>
                </div>
                <p>One response is deterministic; multiple responses are selected by weight.</p>
            </div>
            <livewire:admin.response-manager :endpoint="$endpoint" />
        </section>
    @endif
</x-layouts.dashboard>
