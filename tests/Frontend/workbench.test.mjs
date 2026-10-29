import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync('public/js/workbench.js', 'utf8');
function setup({ narrow = false, unavailable = false, saved = new Map() } = {}) {
    const listeners = new Map();
    const attrs = new Map();
    const toggle = { setAttribute: (key, value) => attrs.set(key, value), focus() { this.focused = true; } };
    const input = { checked: true };
    const panel = { inert: false };
    const document = { body: { dataset: {} }, querySelectorAll: selector => selector === '[data-workbench-toggle]' ? [toggle] : [input], getElementById: () => panel, querySelector: () => toggle, addEventListener: (name, callback) => listeners.set(name, callback) };
    const storage = { getItem(key) { if (unavailable) throw Error('blocked'); return saved.get(key) ?? null; }, setItem(key, value) { if (unavailable) throw Error('blocked'); saved.set(key, value); } };
    const window = { matchMedia: () => ({ matches: narrow, addEventListener() {} }), dispatchEvent() {} };
    vm.runInNewContext(source, { window, document, localStorage: storage, Event: class {} });
    const click = () => listeners.get('click')({ target: { closest: () => toggle } });
    const change = checked => listeners.get('change')({ target: { checked, matches: () => true } });
    return { window, document, saved, panel, attrs, toggle, input, click, change, dispatch: (name, event = {}) => listeners.get(name)(event) };
}
test('desktop sidebar remembers collapse without persisting object data', () => {
    const app = setup();
    assert.equal(app.attrs.get('aria-expanded'), 'true');
    app.click();
    assert.equal(app.panel.inert, true);
    assert.equal(app.saved.get('mockdeck:sidebar-collapsed'), 'true');
    assert.equal(setup({ saved: app.saved }).attrs.get('aria-expanded'), 'false');
    assert.deepEqual([...app.saved.keys()], ['mockdeck:sidebar-collapsed']);
});
test('narrow sidebar is a temporary overlay and Escape restores focus', () => {
    const app = setup({ narrow: true });
    assert.equal(app.panel.inert, true);
    app.click();
    assert.equal(app.panel.inert, false);
    app.dispatch('keydown', { key: 'Escape' });
    assert.equal(app.panel.inert, true);
    assert.equal(app.toggle.focused, true);
    assert.equal(app.saved.size, 0);
});
test('single character opt-out persists and synchronizes after navigation', () => {
    const app = setup();
    app.change(false);
    assert.equal(app.window.MockDeckWorkbench.singleKeyShortcutsEnabled(), false);
    app.dispatch('livewire:navigated');
    assert.equal(app.input.checked, false);
    assert.equal(setup({ saved: app.saved }).window.MockDeckWorkbench.singleKeyShortcutsEnabled(), false);
});
test('blocked browser storage still permits sidebar and shortcut choices', () => {
    const app = setup({ unavailable: true });
    app.click();
    app.change(false);
    assert.equal(app.panel.inert, true);
    assert.equal(app.window.MockDeckWorkbench.singleKeyShortcutsEnabled(), false);
});
