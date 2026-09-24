<x-layouts.dashboard title="Documentation">
    <x-page-header title="Documentation" description="Essential concepts and shortcuts for exact-request mocks.">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
        </x-slot:actions>
    </x-page-header>

    <div class="docs-grid">
        <section class="card docs-card">
            <h2>Core concepts</h2>
            <dl class="definition-list">
                <div><dt>Signature</dt><dd>The canonical method, path, query, selected headers, and body used to identify a request.</dd></div>
                <div><dt>Signature version</dt><dd>The matching-policy variant. V1 includes all headers; later versions ignore cookies, authentication, or all headers.</dd></div>
                <div><dt>Priority</dt><dd>Higher values win before signature specificity. Use it only when more than one endpoint could match.</dd></div>
                <div><dt>Match type</dt><dd>Hash is exact, fallback is a normalized recovery match, and none means no endpoint matched.</dd></div>
            </dl>
        </section>

        <section class="card docs-card">
            <h2>Keyboard shortcuts</h2>
            <dl class="shortcut-list">
                <div><dt><kbd>N</kbd></dt><dd>Create a new endpoint</dd></div>
                <div><dt><kbd>/</kbd></dt><dd>Focus endpoint or log search</dd></div>
                <div><dt><kbd>?</kbd></dt><dd>Open shortcut help</dd></div>
                <div><dt><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Enter</kbd></dt><dd>Save the endpoint form</dd></div>
            </dl>
        </section>
    </div>
</x-layouts.dashboard>
