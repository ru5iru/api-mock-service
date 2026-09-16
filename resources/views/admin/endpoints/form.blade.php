<x-layouts.dashboard :title="$endpoint ? 'Edit endpoint' : 'New endpoint'">
    <div class="breadcrumb">
        <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Endpoints</a>
        <span>/</span>
        <span>{{ $endpoint?->name ?: ($endpoint ? $endpoint->method.' endpoint' : 'New endpoint') }}</span>
    </div>

    <section class="page-heading compact">
        <div>
            <span class="eyebrow">{{ $endpoint ? 'Endpoint #'.$endpoint->id : 'Create mock' }}</span>
            <h1>{{ $endpoint ? 'Edit request signature' : 'Describe the real request' }}</h1>
            <p>Paste curl, choose what participates in matching, then inspect the canonical signature before saving.</p>
        </div>
        @if ($endpoint)
            <span class="method-badge method-{{ strtolower($endpoint->method) }}">{{ $endpoint->method }}</span>
        @endif
    </section>

    <livewire:admin.endpoint-form :endpoint="$endpoint" />

    @if ($endpoint)
        <section class="section-spacer">
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
