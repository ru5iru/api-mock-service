import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

test('shared menu closes outside, restores focus on Escape, and keeps one menu open', () => {
    const listeners = new Map();
    const menu = () => {
        const summary = { focused: false, focus() { this.focused = true; }, setAttribute(name, value) { this[name] = value; }, getAttribute(name) { return this[name] ?? null; } };
        const option = { hidden: false, disabled: false, focus() { this.focused = true; }, getAttribute() { return null; }, closest(selector) { return selector === 'a, button' ? this : null; } };
        const root = {
            open: false,
            querySelector(selector) { return selector === ':scope > summary' ? summary : null; },
            querySelectorAll() { return [option]; },
            matches(selector) { return selector === 'details[data-menu]'; },
            hasAttribute() { return false; },
        };
        return { root, summary, option };
    };
    const first = menu();
    const second = menu();
    const document = {
        querySelectorAll() { return [first.root, second.root]; },
        addEventListener(name, listener) { listeners.set(name, listener); },
    };
    const window = {};
    runInNewContext(readFileSync('public/js/menu.js', 'utf8'), { document, window, MutationObserver: class { observe() {} } });

    assert.equal(first.summary['aria-expanded'], 'false');
    assert.equal(first.summary['aria-haspopup'], 'true');
    window.MockDeckMenu.open(first.root);
    assert.equal(first.root.open, true);
    window.MockDeckMenu.open(second.root);
    assert.equal(first.root.open, false);
    assert.equal(second.root.open, true);

    listeners.get('click')({ target: { closest() { return null; } } });
    assert.equal(second.root.open, false);

    window.MockDeckMenu.open(first.root);
    const escape = { key: 'Escape', target: { closest(selector) { return selector === 'details[data-menu]' ? first.root : null; } }, preventDefault() { this.prevented = true; } };
    listeners.get('keydown')(escape);
    assert.equal(first.root.open, false);
    assert.equal(first.summary.focused, true);
    assert.equal(escape.prevented, true);

    first.root.contains = item => item === second.root;
    window.MockDeckMenu.open(first.root);
    window.MockDeckMenu.open(second.root);
    assert.equal(first.root.open, true, 'Nested menu must keep its navigation parent open');
    assert.equal(second.root.open, true);
    window.MockDeckMenu.close(first.root);
    assert.equal(second.root.open, false, 'Closing a parent must close descendant panels');

    window.MockDeckMenu.open(first.root);
    window.MockDeckMenu.open(second.root);
    first.root.open = false; // Native summary activation, rather than the JS close API.
    listeners.get('toggle')({ target: first.root });
    assert.equal(second.root.open, false, 'Collapsing navigation must close nested top-layer panels');
});
