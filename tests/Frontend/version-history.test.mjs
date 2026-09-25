import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const historyView = readFileSync('resources/views/livewire/admin/revision-history.blade.php', 'utf8');
const endpointView = readFileSync('resources/views/livewire/admin/endpoint-form.blade.php', 'utf8');
const responseView = readFileSync('resources/views/livewire/admin/response-manager.blade.php', 'utf8');
const transferView = readFileSync('resources/views/livewire/admin/config-transfer.blade.php', 'utf8');
const styles = readFileSync('public/css/app.css', 'utf8');
const guide = readFileSync('docs/UI_GUIDE.md', 'utf8');

test('endpoint and response editors expose the shared history disclosure', () => {
    assert.match(endpointView, /revision-history entity-type="endpoint"/);
    assert.match(responseView, /revision-history entity-type="response"/);
    assert.match(historyView, /Compare with current/);
    assert.match(historyView, /Select to compare/);
    assert.match(historyView, /wire:confirm="Restore version/);
});

test('diff viewer reuses code surfaces and has responsive before-after columns', () => {
    assert.match(historyView, /revision-diff-viewer/);
    assert.match(historyView, /renderer-/);
    assert.match(styles, /\.revision-diff-values[^}]*grid-template-columns: repeat\(2/);
    assert.match(styles, /\.revision-diff-values pre[^}]*var\(--code-bg\)/);
    assert.match(styles, /@media \(max-width: 720px\)[\s\S]*\.revision-diff-values/);
    assert.match(guide, /Revision timeline and structural diff viewer/);
});

test('import preview and completion expose grouped version snapshots and undo', () => {
    assert.match(transferView, /will get a version snapshot before this update/);
    assert.match(transferView, /Undo this import/);
    assert.match(transferView, /wire:confirm="Undo this import and restore:/);
});
