@props(['name' => 'theme'])

@php($menuId = 'theme-menu-'.preg_replace('/[^a-z0-9_-]/i', '-', $name))

<div class="theme-menu" data-theme-menu-root>
    <button
        class="theme-trigger"
        type="button"
        data-theme-trigger
        aria-label="Theme: System. Choose theme."
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="{{ $menuId }}"
    >
        <svg data-theme-icon="system" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none">
            <rect x="3" y="4" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/>
            <path d="M8 21h8M12 18v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        <svg data-theme-icon="light" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" hidden>
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8"/>
            <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        <svg data-theme-icon="dark" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" hidden>
            <path d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.5 8.5 0 1 0 20.2 15Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        </svg>
        <span class="sr-only">Current theme: <span data-theme-label>System</span></span>
    </button>
    <div id="{{ $menuId }}" class="theme-menu-panel" role="menu" aria-label="Theme" data-theme-menu hidden>
        @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
            <button type="button" role="menuitemradio" aria-checked="false" value="{{ $value }}" data-theme-option tabindex="-1">
                <span class="theme-option-dot" aria-hidden="true"></span>
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
</div>
