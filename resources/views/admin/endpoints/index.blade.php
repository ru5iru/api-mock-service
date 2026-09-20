<x-layouts.dashboard title="Endpoints">
    <section class="page-heading">
        <div>
            <span class="eyebrow">Mock registry</span>
            <h1>Request endpoints</h1>
            <p>Turn a real curl request into an exact, reusable mock signature.</p>
        </div>
        <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>
            <span aria-hidden="true">＋</span> New endpoint
        </a>
    </section>

    <livewire:admin.endpoint-index />

    <section id="request-log" class="section-spacer">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Diagnostics</span>
                <h2>Recent request log</h2>
            </div>
            <a class="text-link" href="{{ route('dashboard.requests.index') }}" wire:navigate>View full request log →</a>
        </div>
        <livewire:admin.log-viewer :full="false" />
    </section>
</x-layouts.dashboard>
