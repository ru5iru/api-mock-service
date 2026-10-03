(function () {
    'use strict';

    const initialized = new WeakSet();
    window.MockDeck = window.MockDeck || {};
    window.MockDeck.prepareToast = (toast) => {
        if (initialized.has(toast)) return;
        initialized.add(toast);
        let remaining = 6000;
        let started = 0;
        let timer = null;
        let hovered = false;
        let focused = false;
        const pause = () => {
            if (timer === null) return;
            remaining = Math.max(0, remaining - (performance.now() - started));
            window.clearTimeout(timer);
            timer = null;
        };
        const resume = () => {
            if (hovered || focused || timer !== null) return;
            started = performance.now();
            timer = window.setTimeout(() => toast.remove(), remaining);
        };
        toast.addEventListener('mouseenter', () => { hovered = true; pause(); });
        toast.addEventListener('mouseleave', () => { hovered = false; resume(); });
        toast.addEventListener('focusin', () => { focused = true; pause(); });
        toast.addEventListener('focusout', (event) => {
            focused = toast.contains(event.relatedTarget);
            if (!focused) resume();
        });
        resume();
    };
}());
