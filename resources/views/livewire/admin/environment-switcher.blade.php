<details class="environment-switcher" data-disclosure data-environment-menu>
    <summary aria-label="Change active environment" aria-haspopup="menu" aria-expanded="false">
        <span aria-hidden="true" class="environment-dot"></span>
        <span>{{ $active?->name ?? 'Environment' }}</span>
        <span aria-hidden="true">⌄</span>
    </summary>
    <div class="environment-switcher-panel" role="menu" aria-label="Active environment">
        <span class="user-menu-label">Active environment</span>
        @foreach ($environments as $environment)
            <button type="button" role="menuitemradio" aria-checked="{{ $environment->id === $activeEnvironmentId ? 'true' : 'false' }}" tabindex="-1" data-environment-option wire:click="activate({{ $environment->id }})" x-on:click="$el.closest('details').removeAttribute('open')" @class(['active' => $environment->id === $activeEnvironmentId])>
                <span>{{ $environment->name }}</span>
                @if ($environment->id === $activeEnvironmentId)<span aria-hidden="true">✓</span>@endif
            </button>
        @endforeach
        <a href="{{ route('dashboard.environments.index') }}" wire:navigate>Manage environments</a>
    </div>
</details>
