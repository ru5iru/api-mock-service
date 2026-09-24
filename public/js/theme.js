(function () {
    'use strict';

    var storageKey = 'mockdeck-theme';
    var allowedModes = ['system', 'light', 'dark'];
    var root = document.documentElement;
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    var mode = 'system';

    try {
        var stored = window.localStorage.getItem(storageKey);
        mode = allowedModes.indexOf(stored) >= 0 ? stored : 'system';
    } catch (error) {
        mode = 'system';
    }

    function resolvedTheme() {
        return mode === 'system' ? (media.matches ? 'dark' : 'light') : mode;
    }

    function syncThemeColor() {
        var meta = document.querySelector('meta[name="theme-color"]');

        if (meta) {
            meta.setAttribute('content', getComputedStyle(root).getPropertyValue('--theme-color').trim());
        }
    }

    function syncControls() {
        document.querySelectorAll('[data-theme-option]').forEach(function (control) {
            var active = control.value === mode;
            control.setAttribute('aria-checked', String(active));
            control.tabIndex = active ? 0 : -1;
        });

        document.querySelectorAll('[data-theme-label]').forEach(function (label) {
            label.textContent = mode.charAt(0).toUpperCase() + mode.slice(1);
        });

        document.querySelectorAll('[data-theme-trigger]').forEach(function (trigger) {
            trigger.setAttribute('aria-label', 'Theme: ' + mode.charAt(0).toUpperCase() + mode.slice(1) + '. Choose theme.');
        });

        document.querySelectorAll('[data-theme-icon]').forEach(function (icon) {
            icon.hidden = icon.getAttribute('data-theme-icon') !== mode;
        });
    }

    function applyTheme() {
        var resolved = resolvedTheme();
        root.dataset.theme = resolved;
        root.dataset.themeMode = mode;
        root.style.colorScheme = resolved;
        syncControls();
        window.requestAnimationFrame(syncThemeColor);
        window.dispatchEvent(new CustomEvent('mockdeck:theme-changed', {
            detail: { mode: mode, theme: resolved },
        }));
    }

    function closeMenu(rootElement, restoreFocus) {
        if (!rootElement) {
            return;
        }

        var trigger = rootElement.querySelector('[data-theme-trigger]');
        var menu = rootElement.querySelector('[data-theme-menu]');
        if (!trigger || !menu) {
            return;
        }

        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) {
            trigger.focus();
        }
    }

    function closeAllMenus(exception) {
        document.querySelectorAll('[data-theme-menu-root]').forEach(function (rootElement) {
            if (rootElement !== exception) {
                closeMenu(rootElement, false);
            }
        });
    }

    function openMenu(rootElement, focusActive) {
        var trigger = rootElement.querySelector('[data-theme-trigger]');
        var menu = rootElement.querySelector('[data-theme-menu]');
        if (!trigger || !menu) {
            return;
        }

        closeAllMenus(rootElement);
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        if (focusActive) {
            (menu.querySelector('[aria-checked="true"]') || menu.querySelector('[data-theme-option]'))?.focus();
        }
    }

    function setMode(nextMode) {
        if (allowedModes.indexOf(nextMode) < 0) {
            return;
        }

        mode = nextMode;

        try {
            if (mode === 'system') {
                window.localStorage.removeItem(storageKey);
            } else {
                window.localStorage.setItem(storageKey, mode);
            }
        } catch (error) {
            // The selected mode still applies for this page when storage is unavailable.
        }

        root.classList.add('theme-switching');
        applyTheme();
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                root.classList.remove('theme-switching');
            });
        });
    }

    applyTheme();

    media.addEventListener('change', function () {
        if (mode === 'system') {
            applyTheme();
        }
    });

    document.addEventListener('livewire:navigated', function () {
        // Livewire may morph <html>; restore the singleton state before the next paint.
        applyTheme();
        closeAllMenus();
    });

    new MutationObserver(function () {
        if (root.dataset.theme !== resolvedTheme() || root.dataset.themeMode !== mode) {
            applyTheme();
        }
    }).observe(root, { attributes: true, attributeFilter: ['data-theme', 'data-theme-mode'] });

    document.addEventListener('DOMContentLoaded', function () {
        syncControls();
        syncThemeColor();

    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest?.('[data-theme-trigger]');
        if (trigger) {
            var rootElement = trigger.closest('[data-theme-menu-root]');
            var menu = rootElement?.querySelector('[data-theme-menu]');
            if (menu?.hidden) {
                openMenu(rootElement, false);
            } else {
                closeMenu(rootElement, false);
            }
            return;
        }

        var option = event.target.closest?.('[data-theme-option]');
        if (option) {
            setMode(option.value);
            closeMenu(option.closest('[data-theme-menu-root]'), true);
            return;
        }

        closeAllMenus();
    });

    document.addEventListener('keydown', function (event) {
        var trigger = event.target.closest?.('[data-theme-trigger]');
        if (trigger && ['ArrowDown', 'ArrowUp'].indexOf(event.key) >= 0) {
            event.preventDefault();
            openMenu(trigger.closest('[data-theme-menu-root]'), true);
            return;
        }

        var option = event.target.closest?.('[data-theme-option]');
        if (!option) {
            if (event.key === 'Escape') {
                document.querySelectorAll('[data-theme-menu-root]').forEach(function (rootElement) {
                    if (!rootElement.querySelector('[data-theme-menu]')?.hidden) {
                        closeMenu(rootElement, true);
                    }
                });
            }
            return;
        }

        var options = Array.from(option.closest('[data-theme-menu]').querySelectorAll('[data-theme-option]'));
        var currentIndex = options.indexOf(option);
        var nextIndex = currentIndex;
        if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % options.length;
        if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + options.length) % options.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = options.length - 1;
        if (nextIndex !== currentIndex) {
            event.preventDefault();
            options[nextIndex].focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu(option.closest('[data-theme-menu-root]'), true);
        }
    });

    window.MockDeckTheme = {
        getMode: function () { return mode; },
        getResolvedTheme: resolvedTheme,
        setMode: setMode,
    };
}());
