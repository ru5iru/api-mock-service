(function () {
    'use strict';
    let pending = false;
    let leaving = false;
    const read = text => { try { return JSON.parse(text || '{}'); } catch (_) { return {}; } };
    const valueAt = (object, path) => path.split('.').reduce((value, key) => value?.[key], object);
    const normalized = value => {
        if (Array.isArray(value)) return value.filter(item => item !== undefined).map(normalized);
        if (value && typeof value === 'object') return Object.keys(value).length
            ? Object.fromEntries(Object.keys(value).sort().map(key => [key, normalized(value[key])])) : [];
        return value == null ? '' : String(value);
    };
    function put(object, path, value) {
        const parts = path.split('.');
        if (parts.some(key => ['__proto__', 'constructor', 'prototype'].includes(key))) return;
        let parent = object;
        for (const key of parts.slice(0, -1)) parent = parent[key] ??= {};
        parent[parts.at(-1)] = value;
    }
    function scopeDirty(scope) {
        if (scope.dataset.serverDirty === 'true') return true;
        const baseline = read(scope.dataset.draftBaseline);
        const current = read(scope.dataset.draftCurrent);
        scope.querySelectorAll('input, textarea, select').forEach(control => {
            if (control.closest('[data-draft-scope]') !== scope) return;
            const path = [...control.attributes].find(attr => attr.name.startsWith('wire:model'))?.value;
            if (!path || !(path.split('.')[0] in current)) return;
            let value = control.value;
            if (control.type === 'checkbox') {
                const previous = valueAt(current, path);
                value = Array.isArray(previous)
                    ? [...previous.filter(item => String(item) !== control.value), ...(control.checked ? [control.value] : [])].sort()
                    : control.checked;
            }
            if (valueAt(baseline, path) === undefined && value === '') {
                const parts = path.split('.');
                const parent = valueAt(current, parts.slice(0, -1).join('.'));
                if (parent && parts.length > 1) delete parent[parts.at(-1)];
            } else put(current, path, value);
        });
        for (const field of ['tagIds', 'excludedHeaderNames', 'excludedQueryParams']) {
            if (Array.isArray(current[field])) current[field] = current[field].map(String).sort();
            if (Array.isArray(baseline[field])) baseline[field] = baseline[field].map(String).sort();
        }
        return JSON.stringify(normalized(current)) !== JSON.stringify(normalized(baseline));
    }
    let saveInFlight = false;
    let saveRoot;
    const heldControls = new Map();
    const invalidatedResults = new Set();
    let resultAttempt = '';
    function syncResultRows() {
        const attempt = document.querySelector('.endpoint-editor')?.dataset.saveAttempt || '';
        if (attempt !== resultAttempt) {
            resultAttempt = attempt;
            invalidatedResults.clear();
        }
        document.querySelectorAll('[data-save-scope]').forEach(row => {
            if (invalidatedResults.has(row.dataset.saveScope)) row.hidden = true;
        });
    }
    const modelPath = control => [...control.attributes].find(attr => attr.name.startsWith('wire:model'))?.value;
    function holdSaveControls(root) {
        root.querySelectorAll('input, textarea, select, button:not([role="tab"])').forEach(control => {
            if (!heldControls.has(control)) heldControls.set(control, control.disabled);
            control.disabled = true;
        });
    }
    function releaseSaveControls() {
        const held = heldControls.size > 0;
        heldControls.forEach((disabled, control) => { if (control.isConnected) control.disabled = disabled; });
        heldControls.clear();
        saveInFlight = false;
        if (held) window.requestAnimationFrame?.(() => {
            const invalid = [...document.querySelectorAll('[aria-invalid="true"]')].find(control => !control.disabled && control.getClientRects().length);
            invalid?.focus();
        });
    }
    function invalidateScopeResult(event) {
        const control = event.target;
        const scope = control.closest?.('[data-draft-scope]');
        const path = modelPath(control)?.split('.')[0];
        if (!scope || !path) return;
        const owner = scope.dataset.draftScope;
        const id = scope.dataset.editingResponse;
        syncResultRows();
        document.querySelectorAll('[data-save-scope]').forEach(row => {
            if (row.dataset.saveOwner !== owner || !read(row.dataset.saveFields).includes?.(path)) return;
            const key = row.dataset.saveScope;
            if (owner === 'responses' && key !== 'selection' && key.split(':')[1] !== id) return;
            invalidatedResults.add(key);
            row.hidden = true;
        });
    }
    for (const type of ['input', 'change']) document.addEventListener(type, invalidateScopeResult);
    function saveRequest(payload) {
        if (typeof payload === 'string') { try { payload = JSON.parse(payload); } catch (_) { return false; } }
        return payload?.components?.some(component => component.calls?.some(call =>
            ['saveAll', 'finishSavingAll'].includes(call.method)
            || (call.method === '__dispatch' && ['save-response-drafts', 'response-drafts-saved', 'response-drafts-save-failed'].includes(call.params?.[0]))));
    }
    function unconfirmedSave() {
        const root = document.querySelector('.endpoint-editor[data-global-save-editor]');
        if (!root) return;
        releaseSaveControls();
        const text = 'Save result could not be confirmed. Check the saved configuration before trying again.';
        const summary = root.querySelector('[data-save-result]');
        if (summary) {
            summary.hidden = false;
            summary.querySelector('strong').textContent = text;
            summary.querySelectorAll('dd').forEach(value => { if (value.textContent !== 'No changes') value.textContent = 'Needs attention'; });
        }
        root.dataset.serverDirty = 'true';
        const status = document.getElementById('endpoint-action-status');
        if (status) status.textContent = text;
        // State acknowledgment only: never replay a mutation or assume rollback.
        const component = window.Livewire.find(root.getAttribute('wire:id'));
        component.saveResultUnconfirmed().catch(() => {});
    }
    let saveHooksRegistered = false;
    function registerSaveHooks() {
        if (saveHooksRegistered) return;
        saveHooksRegistered = true;
        window.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
            const root = component.el.closest('.endpoint-editor[data-global-save-editor]');
            if (!root || !saveRequest({ components: [commit] })) return;
            if (commit.calls.some(call => call.method === 'saveAll')) {
                saveInFlight = true;
                saveRoot = root;
                holdSaveControls(root);
            }
            succeed(() => {
                const current = document.querySelector('.endpoint-editor');
                if (current?.dataset.savePending === 'true') holdSaveControls(current);
                else releaseSaveControls();
            });
            fail(releaseSaveControls);
        });
        window.Livewire.hook('request', ({ payload, fail }) => {
            if (document.querySelector('.endpoint-editor[data-global-save-editor]') && saveRequest(payload)) fail(({ preventDefault }) => { preventDefault(); unconfirmedSave(); });
        });
    }
    document.addEventListener('livewire:init', registerSaveHooks);
    if (window.Livewire) registerSaveHooks();
    new MutationObserver(() => {
        if (saveInFlight && saveRoot?.isConnected) holdSaveControls(saveRoot);
        syncResultRows();
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-save-attempt'] });
    const isDirty = () => [...document.querySelectorAll('[data-draft-scope]')].some(scopeDirty);
    const sync = () => { window.MockDeck.formDirty = isDirty(); };
    async function leave(action, trigger) {
        if (pending) return;
        pending = true;
        try {
            if (!isDirty() || await window.MockDeck.ask({ title: 'Discard unsaved changes?', message: 'Your unsaved endpoint changes will be lost.', danger: true, confirmLabel: 'Leave page', trigger })) {
                leaving = true;
                action();
            }
        } finally { pending = false; }
    }
    function guard(event) {
        if (leaving || (event.type === 'keydown' && event.key !== 'Enter')) return;
        if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || (event.button !== undefined && event.button !== 0)) return;
        const link = event.target.closest?.('a[href]');
        if (!link || link.getAttribute('href')?.startsWith('#') || !isDirty()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        leave(() => link.click(), link);
    }
    for (const type of ['mousedown', 'keydown', 'click']) document.addEventListener(type, guard, true);
    for (const type of ['input', 'change']) document.addEventListener(type, sync);
    window.addEventListener('beforeunload', event => {
        if (!leaving && isDirty()) { event.preventDefault(); event.returnValue = ''; }
    });
    document.addEventListener('livewire:navigated', () => { leaving = false; sync(); });
    new MutationObserver(sync).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-draft-baseline', 'data-draft-current', 'data-server-dirty'] });
    window.MockDeckEditor = { isDirty, sync, navigate: (url, trigger) => leave(() => window.location.assign(url), trigger) };
    sync();
}());
