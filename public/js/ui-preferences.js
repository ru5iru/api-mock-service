(function () {
    'use strict';

    var densityKey = 'mockdeck:log-density';
    var allowedDensities = ['compact', 'comfortable'];
    var density = 'compact';

    function readLogDensity(storage) {
        try {
            var stored = storage.getItem(densityKey);
            return allowedDensities.indexOf(stored) >= 0 ? stored : 'compact';
        } catch (error) {
            return 'compact';
        }
    }

    function applyLogDensity(root) {
        root.querySelectorAll('[data-log-viewer]').forEach(function (viewer) {
            if (viewer.dataset.logDensity !== density) {
                viewer.dataset.logDensity = density;
            }
        });
        root.querySelectorAll('[data-density-option]').forEach(function (button) {
            var pressed = String(button.dataset.densityOption === density);
            if (button.getAttribute('aria-pressed') !== pressed) {
                button.setAttribute('aria-pressed', pressed);
            }
        });
    }

    function setLogDensity(nextDensity, storage, root) {
        if (allowedDensities.indexOf(nextDensity) < 0) {
            return;
        }

        density = nextDensity;
        try {
            storage.setItem(densityKey, density);
        } catch (error) {
            // The selected density still applies for this page.
        }
        applyLogDensity(root);
    }

    function init(root, storage) {
        density = readLogDensity(storage);
        applyLogDensity(root);

        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-density-option]');
            if (button) {
                setLogDensity(button.dataset.densityOption, storage, root);
            }
        });

        new MutationObserver(function () {
            applyLogDensity(root);
        }).observe(root.body, { childList: true, subtree: true });
    }

    window.MockDeckPreferences = {
        getLogDensity: function () { return density; },
        readLogDensity: readLogDensity,
        setLogDensity: function (nextDensity) {
            setLogDensity(nextDensity, window.localStorage, document);
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document, window.localStorage);
        });
    } else {
        init(document, window.localStorage);
    }
}());
