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
