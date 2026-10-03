import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

test('toast lifetime API loads before inline editor synchronization can run', () => {
    const layout = readFileSync('resources/views/layouts/dashboard.blade.php', 'utf8');
    const tag = layout.match(/<script[^>]*js\/toasts\.js[^>]*>/)[0];
    assert.equal(tag.includes(' defer'), false);
    assert.ok(layout.indexOf('js/toasts.js') < layout.indexOf('const syncEndpointEditor'));
});

function harness() {
    let now = 0;
    const timers = new Map();
    let next = 0;
    const listeners = {};
    const toast = { removed: false, addEventListener(name, fn) { listeners[name] = fn; }, remove() { this.removed = true; }, contains(node) { return node === this; } };
    const window = { setTimeout(fn, delay) { timers.set(++next, { fn, at: now + delay }); return next; }, clearTimeout(id) { timers.delete(id); } };
    runInNewContext(readFileSync('public/js/toasts.js', 'utf8'), { window, performance: { now: () => now }, WeakSet });
    return { toast, listeners, prepare: () => window.MockDeck.prepareToast(toast), advance(ms) { now += ms; for (const [id, timer] of timers) if (timer.at <= now) { timers.delete(id); timer.fn(); } } };
}

test('every toast expires at 6000ms; repeated setup cannot restart its lifetime', () => {
    const h = harness(); h.prepare(); h.advance(5999); h.prepare();
    assert.equal(h.toast.removed, false); h.advance(1); assert.equal(h.toast.removed, true);
});
test('hover and focus pause the remaining time independently', () => {
    const h = harness(); h.prepare(); h.advance(2000); h.listeners.mouseenter();
    h.listeners.focusin(); h.advance(10000); assert.equal(h.toast.removed, false);
    h.listeners.mouseleave(); h.advance(10000); assert.equal(h.toast.removed, false);
    h.listeners.focusout({ relatedTarget: null }); h.advance(3999); assert.equal(h.toast.removed, false);
    h.advance(1); assert.equal(h.toast.removed, true);
});
