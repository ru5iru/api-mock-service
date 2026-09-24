<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'MockDeck' }} · Exact-request API mocking</title>
    <meta name="description" content="Configure deterministic and weighted mock API responses from curl commands.">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="">
    <x-favicon-links />
    <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}" data-navigate-once></script>
    <link rel="preload" href="{{ asset('fonts/jetbrains-mono/jetbrains-mono-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="{{ asset('js/ui-preferences.js') }}?v={{ filemtime(public_path('js/ui-preferences.js')) }}" defer></script>
    <script src="{{ asset('js/template-editor.js') }}?v={{ filemtime(public_path('js/template-editor.js')) }}" defer data-navigate-once></script>
    @livewireStyles
</head>
@php
    $navigationUnmatchedTimestamps = array_values(array_filter(array_map(
        static fn (array $event): ?string => ($event['match_tier'] ?? 'none') === 'none' ? ($event['timestamp'] ?? null) : null,
        app(\App\Services\Logging\LogTailer::class)->recent(100),
    )));
@endphp
<body data-unmatched-timestamps="{{ json_encode($navigationUnmatchedTimestamps) }}">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>
                <x-brand-mark />
                <span>
                    <strong>MockDeck</strong>
                    <small>Exact-request API mocking</small>
                </span>
            </a>

            <nav class="topnav" aria-label="Dashboard navigation">
                <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate
                   class="{{ request()->routeIs('dashboard.endpoints.*') ? 'active' : '' }}"
                   @if (request()->routeIs('dashboard.endpoints.*')) aria-current="page" @endif>
                    Endpoints
                </a>
                <a href="{{ route('dashboard.requests.index') }}" wire:navigate
                   class="{{ request()->routeIs('dashboard.requests.*') ? 'active' : '' }}"
                   @if (request()->routeIs('dashboard.requests.*')) aria-current="page" @endif>
                    Request log <span class="nav-badge" data-unmatched-badge hidden>0</span>
                </a>
                <a href="{{ route('dashboard.config.index') }}" wire:navigate
                   class="{{ request()->routeIs('dashboard.config.*') ? 'active' : '' }}"
                   @if (request()->routeIs('dashboard.config.*')) aria-current="page" @endif>
                    Import / export
                </a>
                <x-theme-control name="theme-header" />
                <details class="user-menu">
                    <summary aria-label="Open user menu">
                        <span class="user-avatar" aria-hidden="true">O</span>
                        <span>{{ config('mock.dashboard_auth.enabled') ? 'Operator' : 'Local' }}</span>
                    </summary>
                    <div class="user-menu-panel">
                        <span class="user-menu-label">{{ config('mock.dashboard_auth.enabled') ? 'Authenticated session' : 'Local access mode' }}</span>
                        <a href="{{ route('dashboard.docs') }}" wire:navigate>Documentation</a>
                        @if (config('mock.dashboard_auth.enabled'))
                            <form method="POST" action="{{ route('dashboard.logout') }}">
                                @csrf
                                <button class="danger-text" type="submit">Sign out</button>
                            </form>
                        @endif
                    </div>
                </details>
            </nav>

            <details class="mobile-nav">
                <summary aria-label="Open dashboard navigation">Menu</summary>
                <nav class="mobile-nav-panel" aria-label="Mobile dashboard navigation">
                    <a href="{{ route('dashboard.endpoints.index') }}" wire:navigate
                       class="{{ request()->routeIs('dashboard.endpoints.*') ? 'active' : '' }}"
                       @if (request()->routeIs('dashboard.endpoints.*')) aria-current="page" @endif>
                        Endpoints
                    </a>
                    <a href="{{ route('dashboard.requests.index') }}" wire:navigate
                       class="{{ request()->routeIs('dashboard.requests.*') ? 'active' : '' }}"
                       @if (request()->routeIs('dashboard.requests.*')) aria-current="page" @endif>
                        Request log <span class="nav-badge" data-unmatched-badge hidden>0</span>
                    </a>
                    <a href="{{ route('dashboard.config.index') }}" wire:navigate
                       class="{{ request()->routeIs('dashboard.config.*') ? 'active' : '' }}"
                       @if (request()->routeIs('dashboard.config.*')) aria-current="page" @endif>
                        Import / export
                    </a>
                    <a href="{{ route('dashboard.docs') }}" wire:navigate>Documentation</a>
                    <x-theme-control name="theme-mobile" />
                    @if (config('mock.dashboard_auth.enabled'))
                        <form method="POST" action="{{ route('dashboard.logout') }}" class="nav-form">
                            @csrf
                            <button class="nav-logout" type="submit">Sign out</button>
                        </form>
                    @else
                        <span class="access-chip"><i></i> Local access</span>
                    @endif
                </nav>
            </details>
        </header>

        <main id="main-content" class="page-shell" tabindex="-1">
            {{ $slot }}
        </main>

        <footer class="footer">
            <span>MockDeck v{{ config('mock.portable_config.generator_version') }}</span>
            <a href="{{ route('dashboard.docs') }}" wire:navigate>Documentation</a>
        </footer>
    </div>

    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false">
        @if (session('status'))
            <div class="toast toast-success" role="status" data-toast>
                <span class="toast-icon" aria-hidden="true">✓</span>
                <span>{{ session('status') }}</span>
                <button type="button" aria-label="Dismiss notification" data-dismiss-toast>×</button>
            </div>
        @endif
    </div>

    <dialog id="shortcut-dialog" class="shortcut-dialog" aria-labelledby="shortcut-title">
        <div class="dialog-heading">
            <div>
                <h2 id="shortcut-title">Shortcuts</h2>
            </div>
            <button class="icon-button" type="button" aria-label="Close shortcut help" data-close-shortcuts>×</button>
        </div>
        <dl class="shortcut-list">
            <div><dt><kbd>N</kbd></dt><dd>New endpoint</dd></div>
            <div><dt><kbd>/</kbd></dt><dd>Focus search</dd></div>
            <div><dt><kbd>?</kbd></dt><dd>Shortcut help</dd></div>
            <div><dt><kbd>Esc</kbd></dt><dd>Close this dialog</dd></div>
        </dl>
    </dialog>

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

        window.MockDeck = window.MockDeck || {};
        window.MockDeck.toast = (message, tone = 'success', action = null) => {
            const region = document.getElementById('toast-region');
            if (!region) return;

            const toast = document.createElement('div');
            toast.className = `toast toast-${tone}`;
            toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
            toast.dataset.toast = '';
            toast.innerHTML = `<span class="toast-icon" aria-hidden="true">${tone === 'danger' ? '!' : '✓'}</span><span></span><button type="button" aria-label="Dismiss notification" data-dismiss-toast>×</button>`;
            toast.children[1].textContent = message;

            if (action?.label && typeof action.callback === 'function') {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'toast-action';
                button.textContent = action.label;
                button.addEventListener('click', action.callback, { once: true });
                toast.insertBefore(button, toast.lastElementChild);
            }

            region.appendChild(toast);
            window.setTimeout(() => toast.remove(), 6000);
        };

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-curl]');
            if (!button || button.disabled) return;

            const originalLabel = button.textContent.trim();
            button.disabled = true;

            try {
                await copyText(button.dataset.copyCurl);
                button.textContent = 'Copied';
                window.MockDeck.toast('Mock curl copied to the clipboard.');
            } catch (error) {
                button.textContent = 'Copy failed';
                window.MockDeck.toast('The mock curl could not be copied.', 'danger');
            }

            window.setTimeout(() => {
                button.textContent = originalLabel;
                button.disabled = false;
            }, 1500);
        });

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-text]');
            if (!button || button.disabled) return;

            try {
                await copyText(button.dataset.copyText);
                window.MockDeck.toast('Copied to the clipboard.');
            } catch (error) {
                window.MockDeck.toast('The value could not be copied.', 'danger');
            }
        });

        const resizeEditor = (editor) => {
            if (!editor?.matches('[data-auto-grow]')) return;
            editor.style.height = 'auto';
            editor.style.height = `${Math.min(Math.max(editor.scrollHeight, 184), 460)}px`;
        };

        document.addEventListener('input', (event) => {
            resizeEditor(event.target);
            if (event.target.closest?.('[data-unsaved-form]')) window.MockDeck.formDirty = true;
        });

        document.addEventListener('change', (event) => {
            if (event.target.closest?.('[data-unsaved-form]')) window.MockDeck.formDirty = true;
        });

        document.addEventListener('dragover', (event) => {
            const dropzone = event.target.closest?.('[data-dropzone]');
            if (!dropzone) return;
            event.preventDefault();
            dropzone.classList.add('is-dragging');
        });

        document.addEventListener('dragleave', (event) => {
            event.target.closest?.('[data-dropzone]')?.classList.remove('is-dragging');
        });

        document.addEventListener('drop', (event) => {
            const dropzone = event.target.closest?.('[data-dropzone]');
            if (!dropzone) return;
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
            const input = dropzone.querySelector('input[type="file"]');
            if (!input || !event.dataTransfer?.files?.length) return;
            input.files = event.dataTransfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        document.addEventListener('click', async (event) => {
            const dirtyButton = event.target.closest('button[wire\\:click]');
            if (dirtyButton?.closest('[data-unsaved-form]')) window.MockDeck.formDirty = true;

            const pasteButton = event.target.closest('[data-paste-curl]');
            if (pasteButton) {
                try {
                    const editor = pasteButton.closest('form')?.querySelector('[data-curl-editor]');
                    editor.value = await navigator.clipboard.readText();
                    editor.dispatchEvent(new Event('input', { bubbles: true }));
                    resizeEditor(editor);
                } catch (error) {
                    window.MockDeck.toast('Clipboard access was denied. Paste into the field manually.', 'danger');
                }
                return;
            }

            const wrapButton = event.target.closest('[data-toggle-wrap]');
            if (wrapButton) {
                const editor = wrapButton.closest('form')?.querySelector('[data-curl-editor]');
                const nowrap = editor?.classList.toggle('no-wrap');
                wrapButton.textContent = `Wrap: ${nowrap ? 'off' : 'on'}`;
                resizeEditor(editor);
                return;
            }

            const secretButton = event.target.closest('[data-secret-value]');
            if (secretButton) {
                const revealed = secretButton.dataset.revealed === 'true';
                secretButton.textContent = revealed ? '••••••••' : secretButton.dataset.secretValue;
                secretButton.dataset.revealed = String(!revealed);
                secretButton.setAttribute('aria-label', `${revealed ? 'Reveal' : 'Hide'} secret value`);
                return;
            }

            if (event.target.closest('[data-dismiss-host-note]')) {
                window.localStorage.setItem('mockdeck:host-note-dismissed', 'true');
                event.target.closest('[data-host-note]')?.remove();
            }
        });

        document.addEventListener('submit', (event) => {
            if (event.target.matches('[data-unsaved-form]')) window.MockDeck.formDirty = false;
        });

        window.addEventListener('beforeunload', (event) => {
            if (!window.MockDeck.formDirty) return;
            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.getAttribute('href')?.startsWith('#') || !window.MockDeck.formDirty || !document.querySelector('[data-unsaved-form]')) return;
            if (window.confirm('Leave this page? Your unsaved endpoint changes will be lost.')) {
                window.MockDeck.formDirty = false;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                const form = document.querySelector('[data-unsaved-form]');
                if (form) {
                    event.preventDefault();
                    form.requestSubmit();
                }
            }
        });

        document.addEventListener('click', (event) => {
            const row = event.target.closest('[data-row-href]');
            if (!row || event.target.closest('a, button, input, select, textarea, summary, [data-stop-row-navigation]')) return;
            window.location.href = row.dataset.rowHref;
        });

        document.addEventListener('keydown', (event) => {
            const row = event.target.closest?.('[data-row-href]');
            if (row && event.key === 'Enter') {
                event.preventDefault();
                window.location.href = row.dataset.rowHref;
            }
        });

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-dismiss-toast]')) {
                event.target.closest('[data-toast]')?.remove();
            }
        });

        document.addEventListener('mockdeck:toast', (event) => {
            window.MockDeck.toast(event.detail?.message ?? 'Done.', event.detail?.tone ?? 'success');
        });

        document.addEventListener('livewire:init', () => {
            Livewire.on('toast', (event) => {
                window.MockDeck.toast(event.message ?? 'Done.', event.tone ?? 'success');
            });
        });

        const syncLogPresentation = () => {
            const viewer = document.querySelector('[data-log-viewer]') ?? document.body;

            try {
                const storageKey = 'mockdeck:last-log-visit';
                const isFullLog = window.location.pathname.endsWith('/dashboard/requests');
                let lastVisit = Number(window.localStorage.getItem(storageKey) ?? 0);
                const timestamps = JSON.parse(viewer.dataset.unmatchedTimestamps || '[]');

                if (isFullLog) {
                    lastVisit = Date.now();
                    window.localStorage.setItem(storageKey, String(lastVisit));
                }

                const count = timestamps.filter((timestamp) => Date.parse(timestamp) > lastVisit).length;
                document.querySelectorAll('[data-unmatched-badge]').forEach((badge) => {
                    const label = count > 99 ? '99+' : String(count);
                    if (badge.textContent !== label) badge.textContent = label;
                    if (badge.hidden !== (count === 0)) badge.hidden = count === 0;
                    if (count > 0) {
                        badge.setAttribute('aria-label', `${count} unmatched requests since last visit`);
                    } else {
                        badge.removeAttribute('aria-label');
                    }
                });
            } catch (error) {
                document.querySelectorAll('[data-unmatched-badge]').forEach((badge) => badge.hidden = true);
            }

            document.querySelectorAll('[data-log-time]').forEach((element) => {
                const date = new Date(element.dateTime);
                if (Number.isNaN(date.getTime())) return;
                const local = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' }).format(date);
                const utc = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'UTC' }).format(date);
                element.title = `Local: ${local} · UTC: ${utc}`;

                if (element.dataset.clock === 'utc') {
                    const utcLabel = `${new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: 'UTC' }).format(date)} UTC`;
                    if (element.textContent.trim() !== utcLabel) element.textContent = utcLabel;
                }
            });

        };

        const syncEndpointEditor = () => {
            document.querySelectorAll('[data-auto-grow]').forEach(resizeEditor);
            document.querySelectorAll('details[data-disclosure]').forEach((details) => {
                details.querySelector(':scope > summary')?.setAttribute('aria-expanded', String(details.open));
            });
            try {
                if (window.localStorage.getItem('mockdeck:host-note-dismissed') === 'true') {
                    document.querySelector('[data-host-note]')?.remove();
                }
            } catch (error) {
                // The note remains visible when storage is unavailable.
            }
        };

        let observedStickyActionBar = null;
        let stickyActionResizeObserver = null;
        const syncStickyActionBar = () => {
            const page = document.querySelector('.page-shell');
            const bar = document.querySelector('[data-sticky-action-bar]');
            if (!page) return;

            if (!bar) {
                stickyActionResizeObserver?.disconnect();
                stickyActionResizeObserver = null;
                observedStickyActionBar = null;
                page.classList.remove('has-sticky-action-bar');
                page.style.removeProperty('--sticky-action-bar-height');
                return;
            }

            const applyHeight = () => {
                const height = Math.ceil(bar.getBoundingClientRect().height);
                if (height > 0) {
                    page.classList.add('has-sticky-action-bar');
                    page.style.setProperty('--sticky-action-bar-height', `${height}px`);
                }
            };

            applyHeight();
            if (observedStickyActionBar !== bar && 'ResizeObserver' in window) {
                stickyActionResizeObserver?.disconnect();
                stickyActionResizeObserver = new ResizeObserver(applyHeight);
                stickyActionResizeObserver.observe(bar);
                observedStickyActionBar = bar;
            }
        };

        const syncSectionNavigation = () => {
            document.querySelectorAll('[data-section-nav]').forEach((navigation) => {
                const links = [...navigation.querySelectorAll('[data-section-link]')];
                let current = links[0] ?? null;

                links.forEach((link) => {
                    const id = link.getAttribute('href')?.replace(/^#/, '');
                    const section = id ? document.getElementById(id) : null;
                    if (section && !section.classList.contains('sticky-action-bar') && section.getBoundingClientRect().top <= 120) {
                        current = link;
                    }
                });

                links.forEach((link) => {
                    if (link === current && link.getAttribute('aria-current') !== 'location') {
                        link.setAttribute('aria-current', 'location');
                    } else if (link !== current && link.hasAttribute('aria-current')) {
                        link.removeAttribute('aria-current');
                    }
                });
            });
        };

        document.addEventListener('toggle', (event) => {
            if (!event.target.matches?.('details[data-disclosure]')) return;
            event.target.querySelector(':scope > summary')?.setAttribute('aria-expanded', String(event.target.open));
        }, true);

        let logSyncFrame = null;
        const scheduleLogSync = () => {
            window.cancelAnimationFrame(logSyncFrame);
            logSyncFrame = window.requestAnimationFrame(() => {
                syncLogPresentation();
                syncEndpointEditor();
                syncStickyActionBar();
                syncSectionNavigation();
            });
        };
        new MutationObserver(scheduleLogSync).observe(document.body, { childList: true, subtree: true });
        document.addEventListener('livewire:navigated', () => {
            window.MockDeck.formDirty = false;
            scheduleLogSync();
        });
        scheduleLogSync();
        document.addEventListener('scroll', scheduleLogSync, { passive: true });

        const shortcutDialog = document.getElementById('shortcut-dialog');
        document.querySelector('[data-close-shortcuts]')?.addEventListener('click', () => shortcutDialog?.close());
        document.addEventListener('keydown', (event) => {
            const target = event.target;
            const isEditing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable;
            if (isEditing || event.ctrlKey || event.metaKey || event.altKey) return;

            if (event.key === 'n' || event.key === 'N') {
                window.location.href = @js(route('dashboard.endpoints.create'));
            } else if (event.key === '/') {
                event.preventDefault();
                document.querySelector('[data-search-shortcut]')?.focus();
            } else if (event.key === '?') {
                shortcutDialog?.showModal();
            }
        });
    </script>
</body>
</html>
