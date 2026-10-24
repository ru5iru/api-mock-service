(function () {
    'use strict';
    let nextId = 0;
    let previous = new Set();
    function reveal(control) {
        for (let parent = control.parentElement; parent; parent = parent.parentElement) {
            if (parent.matches('details:not([data-menu])')) parent.open = true;
        }
        if (control.getClientRects().length) control.focus({ preventScroll: false });
    }
    function sync() {
        const current = new Set();
        let first;
        document.querySelectorAll('[data-validation-errors]').forEach(control => {
            const owned = (control.dataset.validationErrors || '').split(' ');
            const remaining = (control.getAttribute('aria-describedby') || '').split(' ').filter(id => id && !owned.includes(id));
            if (remaining.length) control.setAttribute('aria-describedby', remaining.join(' '));
            else control.removeAttribute('aria-describedby');
            control.removeAttribute('aria-invalid');
            delete control.dataset.validationErrors;
        });
        document.querySelectorAll('[data-error-for]').forEach(error => {
            const path = error.dataset.errorFor;
            const scope = error.closest('[wire\\:id]') || error.closest('form') || document;
            const signature = (scope.getAttribute?.('wire:id') || '') + path + error.textContent;
            current.add(signature);
            error.id ||= `validation-error-${++nextId}`;
            error.setAttribute('role', 'alert');
            scope.querySelectorAll('input, select, textarea').forEach(control => {
                const model = [...control.attributes].find(attr => attr.name.startsWith('wire:model'))?.value || control.name;
                const matches = model === path || (path.endsWith('.*') && (model === path.slice(0, -2) || model?.startsWith(path.slice(0, -1))));
                if (!matches) return;
                const ids = new Set((control.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
                ids.add(error.id);
                control.setAttribute('aria-describedby', [...ids].join(' '));
                control.setAttribute('aria-invalid', 'true');
                control.dataset.validationErrors = [control.dataset.validationErrors, error.id].filter(Boolean).join(' ');
                if (!previous.has(signature)) first ||= control;
            });
        });
        previous = current;
        if (first) {
            reveal(first);
        }
    }
    document.addEventListener('click', event => {
        const link = event.target.closest?.('[data-focus-error]');
        if (!link) return;
        const scope = link.closest('[wire\\:id]') || document;
        const control = [...scope.querySelectorAll('input, select, textarea')].find(input =>
            [...input.attributes].some(attr => attr.name.startsWith('wire:model') && attr.value === link.dataset.focusError));
        if (control) reveal(control);
    });
    let frame;
    const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(sync); };
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('livewire:navigated', schedule);
    schedule();
}());
