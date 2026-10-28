import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the actual shell function/listener, without copying their implementation.
const shell = readFileSync('resources/views/layouts/dashboard.blade.php', 'utf8');
const copyFunction = shell.slice(shell.indexOf('        const copyText ='), shell.indexOf('        window.MockDeck ='));
const start = shell.indexOf("        document.addEventListener('click', async (event) => {");
const handler = shell.slice(start, shell.indexOf("        document.addEventListener('click', async (event) => {", start + 1));

function setup({ secure = true, reject = false, fallback = true, endpointValue } = {}) {
    const state = { clipboard: 'sentinel', toasts: [], timers: [], removed: false, selected: false, appended: false };
    const textarea = { style: {}, setAttribute() {}, select() { state.selected = true; }, remove() { state.removed = true; } };
    const button = { disabled: false, textContent: 'Copy', dataset: endpointValue === undefined ? { copyCurl: "curl 'http://localhost/missing'" } : { copyCurl: '', copyEndpoint: '7' }, closest: () => ({ getAttribute: () => 'component-id' }) };
    const document = {
        addEventListener(type, listener, capture) { state.listener = listener; state.capture = capture; },
        createElement: () => textarea,
        body: { appendChild() { state.appended = true; } },
        execCommand(command) { assert.equal(command, 'copy'); if (fallback) state.clipboard = textarea.value; return fallback; },
    };
    const window = { isSecureContext: secure, setTimeout: callback => state.timers.push(callback),
        MockDeck: { toast: (message, tone = 'success') => state.toasts.push({ message, tone }) },
        Livewire: { find: () => ({ copyMockCurl: async id => { assert.equal(id, 7); return endpointValue; } }) } };
    const navigator = { clipboard: { writeText: async value => { if (reject) throw new Error('Permission denied'); state.clipboard = value; } } };
    vm.runInNewContext(copyFunction + handler, { window, navigator, document });
    return { state, button, click: () => state.listener({ target: { closest: selector => selector === '[data-copy-curl]' ? button : null } }) };
}

test('delegated curl copy runs in capture phase before row click.stop and resets feedback', async () => {
    const { state, button, click } = setup();
    assert.equal(state.capture, true);
    await click();
    assert.equal(state.clipboard, button.dataset.copyCurl);
    assert.equal(button.textContent, 'Copied');
    assert.equal(button.disabled, true);
    assert.deepEqual(state.toasts.map(toast => toast.tone), ['success']);
    state.timers[0]();
    assert.equal(button.textContent, 'Copy');
    assert.equal(button.disabled, false);
});

test('clipboard rejection preserves prior contents and reports only failure', async () => {
    const { state, button, click } = setup({ reject: true });
    await click();
    assert.equal(state.clipboard, 'sentinel');
    assert.equal(button.textContent, 'Copy failed');
    assert.deepEqual(state.toasts.map(toast => toast.tone), ['danger']);
    state.timers[0]();
    assert.equal(button.disabled, false);
});

test('insecure context reuses existing textarea fallback and always removes it', async () => {
    for (const fallback of [true, false]) {
        const { state, button, click } = setup({ secure: false, fallback });
        await click();
        assert.equal(state.appended, true);
        assert.equal(state.selected, true);
        assert.equal(state.removed, true);
        assert.equal(state.clipboard, fallback ? button.dataset.copyCurl : 'sentinel');
        assert.equal(button.textContent, fallback ? 'Copied' : 'Copy failed');
        assert.deepEqual(state.toasts.map(toast => toast.tone), [fallback ? 'success' : 'danger']);
    }
});

test('endpoint copy still resolves at the action boundary; null does not write or claim success', async () => {
    for (const endpointValue of ["curl 'http://localhost/resolved'", null]) {
        const { state, button, click } = setup({ endpointValue });
        await click();
        assert.equal(state.clipboard, endpointValue ?? 'sentinel');
        assert.equal(button.textContent, endpointValue === null ? 'Copy failed' : 'Copied');
        assert.equal(state.toasts.length, endpointValue === null ? 0 : 1, 'Server supplies its specific toast for null results');
        assert.equal(button.dataset.copyCurl, '', 'Resolved request must not be cached in markup');
    }
});
