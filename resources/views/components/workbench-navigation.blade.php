@php
    $section = request()->routeIs('dashboard.endpoints.*') ? 'endpoints' : (request()->routeIs('dashboard.logs.*', 'dashboard.requests.*', 'dashboard.callbacks.*') ? 'logs' : (request()->routeIs('dashboard.config.*') ? 'transfer' : (request()->routeIs('dashboard.environments.*') ? 'environments' : 'docs')));
    $destinations = [
        'endpoints' => ['Endpoints', route('dashboard.endpoints.index')],
        'logs' => ['Logs', route('dashboard.logs.index')],
        'transfer' => ['Import / export', route('dashboard.config.index')],
        'environments' => ['Environments', route('dashboard.environments.index')],
        'docs' => ['Documentation', route('dashboard.docs')],
    ];
@endphp
<nav class="topnav workbench-rail" aria-label="Dashboard navigation">
    @foreach ($destinations as $key => [$label, $url])
        <a href="{{ $url }}" wire:navigate @class(['active' => $section === $key]) aria-label="{{ $label }}" title="{{ $label }}" @if($section === $key) aria-current="page" @endif>
            <x-workbench-icon :name="$key" /><span class="workbench-nav-label">{{ $label }}</span>
            @if($key === 'logs')<span class="nav-badge" data-unmatched-badge hidden>0</span>@endif
        </a>
    @endforeach
</nav>
<aside id="workbench-context" class="workbench-context" aria-label="Workspace sidebar">
    <div class="workbench-context-heading">{{ $destinations[$section][0] }}</div>
    @if($section === 'endpoints')
        <div class="workbench-context-links">
            <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate>All endpoints</a>
            <a href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint <kbd>N</kbd></a>
        </div>
        @if(request()->routeIs('dashboard.endpoints.create', 'dashboard.endpoints.edit'))
            <livewire:admin.endpoint-index :explorer="true" :current-endpoint-id="request()->route('endpoint')?->id" :key="'workbench-explorer'" />
        @else
            <div class="workbench-context-note">Find and manage mocks using the registry filters. Select rows for bulk actions.</div>
            <div class="workbench-context-links"><a href="{{ route('dashboard.config.index') }}" wire:navigate>Import a collection</a><a href="{{ route('dashboard.docs').'#getting-started' }}" wire:navigate>Create your first mock</a></div>
        @endif
    @elseif($section === 'logs')
        <div class="workbench-context-links">
            <a href="{{ route('dashboard.logs.index', ['type' => 'requests']) }}" wire:navigate>Requests</a>
            <a href="{{ route('dashboard.logs.index', ['type' => 'callbacks']) }}" wire:navigate>Callbacks</a>
            <a href="{{ route('dashboard.logs.index', ['type' => 'requests', 'unmatchedOnly' => true]) }}" wire:navigate>Unmatched requests</a>
            <a href="{{ route('dashboard.logs.index', ['type' => 'requests', 'status' => '5xx']) }}" wire:navigate>Server errors</a>
        </div>
        <p class="workbench-context-note">Filters inspect retained traffic. They do not change the active Environment.</p>
    @elseif($section === 'transfer')
        <p class="workbench-context-note">Transfer native MockDeck 1.5 configuration or import Postman Collection 2.1. Preview changes before confirming.</p>
        <div class="workbench-context-links"><a href="{{ route('dashboard.docs').'#config-transfer' }}" wire:navigate>Import / export help</a></div>
    @elseif($section === 'environments')
        <p class="workbench-context-note">Inspect and edit variables on this page. The header switch changes the instance-wide active Environment.</p>
    @else
        <div class="workbench-context-links">
            @foreach(['getting-started' => 'Getting started', 'request-matching' => 'Request matching', 'response-templating' => 'Response templates', 'async-callbacks' => 'Callbacks', 'config-transfer' => 'Import / export', 'isolated-test-runs' => 'MCP and test runs'] as $anchor => $label)
                <a href="{{ route('dashboard.docs').'#'.$anchor }}" wire:navigate>{{ $label }}</a>
            @endforeach
        </div>
    @endif
    <div class="workbench-context-footer">{{ config('mock.dashboard_auth.enabled') ? 'Operator session' : 'Local access' }}<br>Configuration is saved on the server.</div>
</aside>
