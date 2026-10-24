import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function harness() {
    const listeners = {};
    const controls = [];
    const scope = { dataset: { draftBaseline: '{"body":"saved"}', draftCurrent: '{"body":"saved"}' }, querySelectorAll: () => controls };
    const control = { type: 'textarea', value: 'saved', attributes: [{ name: 'wire:model', value: 'body' }], closest: () => scope };
    controls.push(control);
    let accepted = false; let prompts = 0; let destination = '';
    const window = { MockDeck: { ask: async () => { prompts++; return accepted; } }, location: { assign: url => { destination = url; } }, addEventListener: (type, callback) => { listeners[type] = callback; } };
    const document = { body: {}, querySelectorAll: () => [scope], addEventListener: (type, callback) => { (listeners[type] ||= []).push(callback); } };
    vm.runInNewContext(readFileSync('public/js/editor-drafts.js', 'utf8'), { window, document, MutationObserver: class { observe() {} } });
    return { window, scope, control, listeners, allow: () => { accepted = true; }, prompts: () => prompts, destination: () => destination };
}

test('inspection stays clean; deferred edits and reverts compare with persisted baseline', () => {
    const h = harness();
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
    assert.equal(h.listeners.click.length, 1); // navigation only, no blanket dirty-on-click listener
    h.control.value = 'draft';
    assert.equal(h.window.MockDeckEditor.isDirty(), true);
    h.control.value = 'saved';
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
});

test('shortcut navigation requires the same consent and retains drafts when cancelled', async () => {
    const h = harness(); h.control.value = 'draft';
    await h.window.MockDeckEditor.navigate('/dashboard/endpoints/create');
    assert.equal(h.destination(), ''); assert.equal(h.prompts(), 1);
    assert.equal(h.window.MockDeckEditor.isDirty(), true);
    h.allow(); await h.window.MockDeckEditor.navigate('/dashboard/endpoints/create');
    assert.equal(h.destination(), '/dashboard/endpoints/create');
});

test('a failed submit cannot clear protection; successful scope baselines clear only that scope', () => {
    const h = harness(); h.control.value = 'draft';
    assert.equal(h.listeners.submit, undefined);
    const event = { preventDefault() { this.prevented = true; } };
    h.listeners.beforeunload(event); assert.equal(event.prevented, true);
    h.scope.dataset.draftBaseline = '{"body":"draft"}';
    h.scope.dataset.draftCurrent = '{"body":"draft"}';
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
    h.scope.dataset.serverDirty = 'true';
    assert.equal(h.window.MockDeckEditor.isDirty(), true);
});

test('non-scalar drafts ignore object key ordering and checkbox set ordering', () => {
    const h = harness();
    h.scope.dataset.draftBaseline = '{"body":"saved","tagIds":[1,2],"environmentOverrides":{"2":"0","1":"1"}}';
    h.scope.dataset.draftCurrent = '{"tagIds":["2","1"],"environmentOverrides":{"1":"1","2":"0"},"body":"saved"}';
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
});

test('inherited blank environment controls stay clean and can revert a deferred override', () => {
    const h = harness();
    h.scope.dataset.draftBaseline = '{"environmentOverrides":[]}';
    h.scope.dataset.draftCurrent = '{"environmentOverrides":[]}';
    h.control.attributes = [{ name: 'wire:model', value: 'environmentOverrides.7' }];
    h.control.value = '';
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
    h.control.value = '0';
    assert.equal(h.window.MockDeckEditor.isDirty(), true);
    h.scope.dataset.draftCurrent = '{"environmentOverrides":{"7":"0"}}';
    h.control.value = '';
    // Missing and empty maps both mean inherit, but PHP encodes an empty map as [].
    assert.equal(h.window.MockDeckEditor.isDirty(), false);
});
