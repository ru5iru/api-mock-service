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
        var option = event.target.closest?.('[data-theme-option]');
        if (option) setMode(option.value);
    });

    window.MockDeckTheme = {
        getMode: function () { return mode; },
        getResolvedTheme: resolvedTheme,
        setMode: setMode,
    };
}());
