@props(['name'])
<svg {{ $attributes->class('workbench-icon') }} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('endpoints') <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /> @break
        @case('logs') <path d="M4 5h16M4 12h16M4 19h10m4-3 3 3-3 3" /> @break
        @case('transfer') <path d="M4 8V4h16v4M4 16v4h16v-4M12 8v8m-4-4 4 4 4-4" /> @break
        @case('environments') <path d="M4 7h16M4 17h16" /><circle cx="9" cy="7" r="3" /><circle cx="15" cy="17" r="3" /> @break
        @case('docs') <path d="M12 5C9 3 5 3 2 4v15c4-1 7-1 10 1 3-2 6-2 10-1V4c-3-1-7-1-10 1Zm0 0v15" /> @break
        @case('plus') <path d="M12 5v14M5 12h14" /> @break
        @case('copy') <rect x="8" y="8" width="12" height="13" rx="2" /><path d="M16 8V3H3v13h5" /> @break
        @case('sidebar') <rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" /> @break
    @endswitch
</svg>
