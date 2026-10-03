(function () {
    'use strict';
    const active = new Map();
    function close(panel) {
        if (panel?.matches(':popover-open')) panel.hidePopover();
        active.delete(panel);
    }
    function position(anchor, panel) {
        let rect = anchor.getBoundingClientRect();
        const gap = 8;
        const edge = 16;
        const sticky = document.querySelector('[data-sticky-action-bar]')?.getBoundingClientRect().height ?? 0;
        const ceiling = anchor.closest('.topbar') ? edge : Math.max(edge, (document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0) + gap);
        let floor = window.innerHeight - sticky - edge;
        if (panel.hasAttribute('data-overlay-editor')) {
            const line = Math.max(ceiling, Math.min(rect.top + 24, floor - 32));
            rect = { ...rect.toJSON(), top: line, bottom: line + 24 };
        }
        const width = Math.min(parseFloat(panel.dataset.panelWidth) || panel.getBoundingClientRect().width || 290, document.documentElement.clientWidth - edge * 2);
        const left = Math.max(edge, Math.min(rect.left, document.documentElement.clientWidth - width - edge));
        const toast = document.querySelector('#toast-region');
        if (toast?.children.length) {
            const box = toast.getBoundingClientRect();
            if (left < box.right && left + width > box.left) floor = Math.min(floor, box.top - gap);
        }
        if (rect.bottom < ceiling || rect.top > floor) {
            anchor.closest('details[data-menu]')?.removeAttribute('open');
            close(panel);
            return;
        }
        const below = Math.max(0, floor - rect.bottom - gap);
        const above = Math.max(0, rect.top - ceiling - gap);
        const limit = parseFloat(panel.dataset.panelHeight) || 420;
        const natural = Math.min(panel.scrollHeight || limit, limit);
        const down = below >= natural || below >= above;
        const available = down ? below : above;
        if (available < 32) {
            anchor.closest('details[data-menu]')?.removeAttribute('open');
            close(panel);
            return;
        }
        const height = Math.min(natural, available);
        const top = down ? Math.min(rect.bottom + gap, floor - height) : Math.max(ceiling, rect.top - height - gap);
        Object.assign(panel.style, { position: 'fixed', inset: 'auto', margin: '0', width: `${width}px`, maxHeight: `${height}px`, overflowY: 'auto', left: `${left}px`, top: `${top}px` });
    }
    function open(anchor, panel) {
        if (!anchor || !panel) return;
        if (!panel.dataset.panelWidth) panel.dataset.panelWidth = String(panel.getBoundingClientRect().width || 290);
        if (typeof panel.showPopover === 'function') {
            panel.setAttribute('popover', 'manual');
            if (!panel.matches(':popover-open')) panel.showPopover();
        }
        active.set(panel, anchor);
        position(anchor, panel);
    }
    let frame;
    const refresh = () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => active.forEach((anchor, panel) => {
            if (!anchor.isConnected || !panel.isConnected) close(panel);
            else position(anchor, panel);
        }));
    };
    document.addEventListener('scroll', refresh, { passive: true, capture: true });
    window.addEventListener('resize', refresh);
    document.addEventListener('livewire:navigated', () => active.forEach((anchor, panel) => close(panel)));
    document.addEventListener('toggle', refresh, true);
    new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('pointerover', (event) => {
        const tip = event.target.closest?.('.help-tip');
        if (tip) open(tip, tip.querySelector('.help-tip-content'));
    });
    document.addEventListener('pointerout', (event) => {
        const tip = event.target.closest?.('.help-tip');
        if (tip && !tip.contains(event.relatedTarget) && !tip.contains(document.activeElement)) close(tip.querySelector('.help-tip-content'));
    });
    document.addEventListener('focusin', (event) => {
        const tip = event.target.closest?.('.help-tip');
        if (tip) open(tip, tip.querySelector('.help-tip-content'));
    });
    document.addEventListener('focusout', (event) => {
        const tip = event.target.closest?.('.help-tip');
        if (tip && !tip.contains(event.relatedTarget) && !tip.matches(':hover')) close(tip.querySelector('.help-tip-content'));
    });
    window.MockDeckPanels = { open, close, position };
}());
