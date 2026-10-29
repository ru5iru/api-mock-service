import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

test('Faker catalog retries after rejection and reuses a successful response', async () => {
    const source = readFileSync('public/js/template-editor.js', 'utf8');
    const functionBody = source.slice(source.indexOf('    function catalog()'), source.indexOf('    function activeFragment'));
    let calls = 0;
    const catalog = vm.runInNewContext('let catalogPromise = null;\n' + functionBody + '\ncatalog;', {
        fetch: async () => { calls++; if (calls === 1) throw Error('temporary outage'); return { ok: true, json: async () => ['internet.email'] }; },
    });
    await assert.rejects(catalog(), /temporary outage/);
    assert.equal((await catalog())[0], 'internet.email');
    await catalog(); assert.equal(calls, 2);
});

test('search recovery keeps the picker open and selection cues identify saved state accurately', () => {
    const row = readFileSync('resources/views/livewire/admin/partials/schema-row.blade.php', 'utf8');
    const responses = readFileSync('resources/views/livewire/admin/response-manager.blade.php', 'utf8');
    assert.match(row, /No matching Faker methods/); assert.match(row, /data-faker-clear/);
    assert.match(readFileSync('public/js/menu.js', 'utf8'), /\[data-faker-clear\]/);
    assert.match(responses, /\$selectionDirty \? 'Draft' : 'Saved'/);
    assert.match(responses, /:checked="\$editingId === \$response->id"/);
    assert.doesNotMatch(responses, /Currently on call|\$sequencePosition \+ 1/);
});


test('advanced help explains condition syntax, callback scope and restore consequences', () => {
    const responses = readFileSync('resources/views/livewire/admin/response-manager.blade.php', 'utf8');
    const callbacks = readFileSync('resources/views/livewire/admin/partials/callback-settings.blade.php', 'utf8');
    const history = readFileSync('resources/views/livewire/admin/revision-history.blade.php', 'utf8');
    assert.match(responses, /Dot-separated JSON path/);
    assert.match(responses, /Include regex delimiters/);
    assert.match(callbacks, /Timing and retries/);
    assert.match(callbacks, /Locale and Seed settings in the Responses pane in Editor/);
    assert.match(callbacks, /wire:target="previewCallback"/);
    assert.match(history, /Unsaved drafts for this item will be discarded/);
});
