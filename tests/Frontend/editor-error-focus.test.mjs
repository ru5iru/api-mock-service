import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

test('rule error summary opens the correct nested disclosures and focuses its field', () => {
    const listeners = {};
    const details = { open: false, parentElement: null, matches: () => true };
    const input = { attributes: [{ name: 'wire:model', value: 'responseRules.42.0.value' }], parentElement: details, getClientRects: () => [1], focus() { this.focused = true; } };
    const scope = { querySelectorAll: () => [input] };
    const link = { dataset: { focusError: 'responseRules.42.0.value' }, closest: () => scope };
    const document = { body: {}, addEventListener: (event, callback) => { listeners[event] = callback; } };
    vm.runInNewContext(readFileSync('public/js/validation.js', 'utf8'), { document, MutationObserver: class { observe() {} }, requestAnimationFrame() {}, cancelAnimationFrame() {} });
    listeners.click({ target: { closest: () => link } });
    assert.equal(details.open, true); assert.equal(input.focused, true);
});

test('tooltips share one scoped Alpine ID for trigger description and tooltip content', () => {
    const view = readFileSync('resources/views/components/help-tip.blade.php', 'utf8');
    assert.match(view, /<button type="button"/);
    assert.match(view, /x-id="\['help-description'\]"/);
    assert.match(view, /x-bind:aria-describedby="\$id\('help-description'\)"/);
    assert.match(view, /x-bind:id="\$id\('help-description'\)"/);
});
