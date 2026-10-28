import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function harness(livewire) {
    const listeners = {};
    const controls = [];
    const scope = { dataset: { draftBaseline: '{"body":"saved"}', draftCurrent: '{"body":"saved"}' }, querySelectorAll: () => controls };
    const control = { type: 'textarea', value: 'saved', disabled: false, isConnected: true, attributes: [{ name: 'wire:model', value: 'body' }], closest: () => scope };
    controls.push(control);
    let accepted = false; let prompts = 0; let destination = '';
    const window = { Livewire: livewire, MockDeck: { ask: async () => { prompts++; return accepted; } }, location: { assign: url => { destination = url; } }, addEventListener: (type, callback) => { listeners[type] = callback; } };
    const rows = [];
    const observers = [];
    const document = { body: {}, querySelector: () => scope, getElementById: () => null, querySelectorAll: selector => selector === '[data-save-scope]' ? rows : [scope], addEventListener: (type, callback) => { (listeners[type] ||= []).push(callback); } };
    vm.runInNewContext(readFileSync('public/js/editor-drafts.js', 'utf8'), { window, document, MutationObserver: class { constructor(callback) { observers.push(callback); } observe() {} } });
    return { window, document, scope, control, listeners, rows, observers, allow: () => { accepted = true; }, prompts: () => prompts, destination: () => destination };
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

test('global saves hold editable controls through both transactions and release on a handled failure', () => {
    const hooks = {};
    const h = harness({ hook: (name, callback) => { hooks[name] = callback; } });
    let succeed;
    hooks.commit({ component: { el: { closest: () => h.scope } }, commit: { calls: [{ method: 'saveAll' }] }, succeed: fn => { succeed = fn; }, fail() {} });
    assert.equal(h.control.disabled, true);
    h.scope.dataset.savePending = 'true';
    succeed();
    assert.equal(h.control.disabled, true);
    h.scope.dataset.savePending = 'false';
    succeed();
    assert.equal(h.control.disabled, false);
    assert.equal(h.control.value, 'saved');
});

test('the new-endpoint path does not acquire existing-endpoint global save handling', () => {
    const hooks = {};
    const h = harness({ hook: (name, callback) => { hooks[name] = callback; } });
    hooks.commit({ component: { el: { closest: () => null } }, commit: { calls: [{ method: 'saveAll' }] } });
    assert.equal(h.control.disabled, false);
    h.document.querySelector = () => null;
    let intercepted = false;
    hooks.request({ payload: { components: [{ calls: [{ method: 'saveAll' }] }] }, fail: () => { intercepted = true; } });
    assert.equal(intercepted, false);
});

test('a later edit hides only its own stale persistence result', () => {
    const h = harness();
    h.scope.dataset.draftScope = 'responses';
    h.scope.dataset.editingResponse = '7';
    const row = (key, fields) => ({ hidden: false, dataset: { saveOwner: 'responses', saveScope: key, saveFields: JSON.stringify(fields) } });
    h.rows.push(row('response:7', ['body']), row('callback:7', ['callbackSigningSecret']), row('response:8', ['body']));
    h.listeners.input.forEach(fn => fn({ target: h.control }));
    assert.equal(h.rows[0].hidden, true);
    assert.equal(h.rows[1].hidden, false);
    assert.equal(h.rows[2].hidden, false);
    h.rows[0].hidden = false; // a parent morph must not resurrect the stale result
    h.observers.forEach(callback => callback());
    assert.equal(h.rows[0].hidden, true);
    h.scope.dataset.saveAttempt = 'a-new-attempt';
    h.rows[0].hidden = false;
    h.observers.forEach(callback => callback());
    assert.equal(h.rows[0].hidden, false);
});

test('lost save acknowledgement is uncertain, stays dirty and never replays the mutation', async () => {
    const hooks = {};
    let acknowledgements = 0;
    const h = harness({ hook: (name, fn) => { hooks[name] = fn; }, find: () => ({ saveResultUnconfirmed: async () => { acknowledgements++; } }) });
    const heading = { textContent: '' };
    const values = [{ textContent: 'Not saved' }, { textContent: 'Saved' }];
    const summary = { hidden: true, querySelector: () => heading, querySelectorAll: () => values };
    h.scope.querySelector = () => summary;
    h.scope.getAttribute = () => 'editor';
    let fail;
    hooks.request({ payload: JSON.stringify({ components: [{ calls: [{ method: '__dispatch', params: ['response-drafts-saved'] }] }] }), fail: fn => { fail = fn; } });
    let prevented = false;
    fail({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(heading.textContent, 'Save result could not be confirmed. Check the saved configuration before trying again.');
    assert.deepEqual(values.map(item => item.textContent), ['Needs attention', 'Needs attention']);
    assert.equal(summary.hidden, false);
    assert.equal(h.window.MockDeckEditor.isDirty(), true);
    assert.equal(acknowledgements, 1); // state only, no saveAll/child mutation
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
