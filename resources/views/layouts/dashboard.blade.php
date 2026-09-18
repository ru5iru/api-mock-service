<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'MockDeck' }} · Exact-request API mocking</title>
    <meta name="description" content="Configure deterministic and weighted mock API responses from curl commands.">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @livewireStyles
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>
                <span class="brand-mark" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <span>
                    <strong>MockDeck</strong>
                    <small>Exact-request API mocking</small>
                </span>
            </a>

            <nav class="topnav" aria-label="Dashboard navigation">
                <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate
                   class="{{ request()->routeIs('dashboard.endpoints.*') ? 'active' : '' }}">
                    Endpoints
                </a>
                <a href="{{ route('dashboard.endpoints.index') }}#request-log">Request log</a>
                @if (config('mock.dashboard_auth.enabled'))
                    <form method="POST" action="{{ route('dashboard.logout') }}" class="nav-form">
                        @csrf
                        <button class="nav-logout" type="submit">Sign out</button>
                    </form>
                @else
                    <span class="access-chip"><i></i> Local access</span>
                @endif
            </nav>
        </header>

        <main class="page-shell">
            @if (session('status'))
                <div class="flash" role="status">
                    <span class="flash-icon">✓</span>
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="footer">
            <span>MockDeck</span>
            <span>Hash-first matching · JSON logs · no request-log table</span>
        </footer>
    </div>

    @livewireScripts
    <script>
        const copyText = async (value) => {
            if (navigator.clipboard?.writeText && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                return;
            }

            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                if (!document.execCommand('copy')) {
                    throw new Error('Copy command was rejected.');
                }
            } finally {
                textarea.remove();
            }
        };

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-curl]');
            if (!button || button.disabled) return;

            const originalLabel = button.textContent.trim();
            button.disabled = true;

            try {
                await copyText(button.dataset.copyCurl);
                button.textContent = 'Copied';
            } catch (error) {
                button.textContent = 'Copy failed';
            }

            window.setTimeout(() => {
                button.textContent = originalLabel;
                button.disabled = false;
            }, 1500);
        });
    </script>
</body>
</html>
