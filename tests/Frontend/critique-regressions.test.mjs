import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = path => readFileSync(path, 'utf8');

test('environment cards shrink and keep actions within their own scroll region', () => {
    const css = read('public/css/app.css');
    assert.match(css, /\.environment-editor \{[^}]*grid-template-columns: minmax\(0, 1fr\)/);
    assert.match(css, /\.environment-editor > \.card, \.environment-editor \.table-scroll \{[^}]*min-width: 0; max-width: 100%/);
    assert.match(css, /\.environment-editor \.inline-edit-row \{ flex-wrap: wrap/);
});

test('history variant beats generic dialog width and adapts to its own container', () => {
    const css = read('public/css/app.css');
    assert.match(css, /\.ui-dialog\.history-dialog \{ width: min\(var\(--history-dialog-width\)/);
    assert.match(css, /\.revision-history-panel \{ container-type: inline-size/);
    assert.match(css, /@container \(max-width: 640px\)[\s\S]*\.revision-row \{ grid-template-columns: minmax\(0, 1fr\)/);
});

test('Enter navigates a registry row without intercepting its descendant controls', async () => {
    const { default: vm } = await import('node:vm');
    const source = read('resources/views/layouts/dashboard.blade.php');
    const handler = source.match(/document.addEventListener\('keydown', \(event\) => \{\s*const row = event.target.closest\?\.\('\[data-row-href\]'\);[\s\S]*?\n        \}\);/)[0];
    let listener; let destination = '';
    vm.runInNewContext(handler, { document: { addEventListener: (_, fn) => { listener = fn; } }, window: { location: { set href(value) { destination = value; } } } });
    const row = { dataset: { rowHref: '/edit' }, closest: () => row };
    let prevented = false;
    const child = { closest: () => row };
    for (const key of ['Enter', ' ']) listener({ target: child, key, preventDefault: () => { prevented = true; } });
    assert.equal(destination, ''); assert.equal(prevented, false);
    listener({ target: row, key: 'Enter', preventDefault: () => { prevented = true; } });
    assert.equal(destination, '/edit'); assert.equal(prevented, true);
});

test('curl entry leads optional settings and response identity is shared across tabs', () => {
    const form = read('resources/views/livewire/admin/endpoint-form.blade.php');
    assert.ok(form.indexOf('class="field curl-field"') < form.indexOf('class="endpoint-control-grid"'));
    const responses = read('resources/views/livewire/admin/response-manager.blade.php');
    assert.equal((responses.match(/<x-response-identity /g) || []).length, 2);
    assert.match(responses, /\$selectionDirty \? 'Unsaved selection changes\.'/);
});

test('autocomplete uses stable option IDs and synchronizes ARIA after morphs', () => {
    const source = read('public/js/template-editor.js');
    assert.match(source, /button.id = popover.id \+ '-option-' \+ index/);
    assert.match(source, /setAttribute\('aria-activedescendant', selected.id\)/);
    assert.match(source, /new MutationObserver/);
    assert.match(source, /removeAttribute\('aria-activedescendant'\)/);
});

test('all validation messages expose their model path and share accessible association code', () => {
    const source = read('public/js/validation.js');
    assert.match(source, /setAttribute\('aria-invalid', 'true'\)/);
    assert.match(source, /setAttribute\('aria-describedby'/);
    assert.match(source, /reveal\(first\)/);
    assert.match(source, /control.focus/);
    for (const path of ['resources/views/layouts/dashboard.blade.php', 'resources/views/auth/login.blade.php']) assert.match(read(path), /js\/validation.js/);
});
