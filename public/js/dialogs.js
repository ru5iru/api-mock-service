(function () {
    'use strict';
    const approved = new WeakSet();
    const openers = new WeakMap();
    let pending = null;
    window.MockDeck = window.MockDeck || {};
    window.MockDeck.openDialog = (dialog, trigger = document.activeElement) => {
        if (!dialog || dialog.open) return;
        openers.set(dialog, trigger);
        dialog.showModal();
    };
    window.MockDeck.ask = ({ title = 'Confirm action', message, danger = false, confirmLabel = 'Continue', trigger = document.activeElement }) => {
        if (pending) return Promise.resolve(false);
        const dialog = document.getElementById('confirm-dialog');
        if (!dialog) return Promise.resolve(false);
        dialog.querySelector('h2').textContent = title;
        dialog.querySelector('[data-confirm-message]').textContent = message;
        const button = dialog.querySelector('[data-confirm-accept]');
        button.textContent = confirmLabel;
        button.className = `button ${danger ? 'button-danger' : 'button-primary'}`;
        dialog.returnValue = '';
        return new Promise(resolve => {
            pending = resolve;
            window.MockDeck.openDialog(dialog, trigger);
            dialog.querySelector('[data-confirm-cancel]').focus();
        });
    };
    document.addEventListener('click', async event => {
        const trigger = event.target.closest?.('[data-confirm]');
        if (!trigger || trigger.disabled || approved.delete(trigger)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const accepted = await window.MockDeck.ask({
            title: trigger.dataset.confirmTitle || 'Confirm action', message: trigger.dataset.confirm,
            danger: trigger.matches('.danger, .danger-text, .button-danger') || trigger.dataset.confirmDanger === 'true',
            confirmLabel: trigger.dataset.confirmLabel || trigger.textContent.trim() || 'Continue', trigger,
        });
        if (accepted && trigger.isConnected && !trigger.disabled) {
            approved.add(trigger);
            trigger.click();
        }
    }, true);
    document.addEventListener('click', event => {
        const open = event.target.closest?.('[data-dialog-open]');
        if (open) window.MockDeck.openDialog(document.getElementById(open.dataset.dialogOpen), open);
        const dialog = event.target.closest?.('dialog');
        if (!dialog) return;
        if (event.target.closest('[data-confirm-accept]')) dialog.close('confirmed');
        else if (event.target.closest('[data-close-dialog], [data-confirm-cancel]')) dialog.close('cancelled');
    });
    document.addEventListener('close', event => {
        const dialog = event.target;
        if (!dialog.matches?.('dialog.ui-dialog')) return;
        const trigger = openers.get(dialog);
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
        openers.delete(dialog);
        if (dialog.id === 'confirm-dialog' && pending) {
            const resolve = pending;
            pending = null;
            resolve(dialog.returnValue === 'confirmed');
        }
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const dialog = event.target.closest?.('dialog.ui-dialog[open]');
        if (!dialog) return;
        const controls = [...dialog.querySelectorAll('button, input, select, textarea, a[href], summary, [tabindex]')]
            .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
        const first = controls[0];
        const last = controls.at(-1);
        if (!first) { event.preventDefault(); dialog.focus(); return; }
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first.focus();
        }
    }, true);
}());
