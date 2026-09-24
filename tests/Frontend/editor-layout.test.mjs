import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('public/css/app.css', 'utf8');
const editor = readFileSync('resources/views/livewire/admin/endpoint-form.blade.php', 'utf8');
const shell = readFileSync('resources/views/layouts/dashboard.blade.php', 'utf8');

test('Create endpoint reserves the measured sticky action-bar height', () => {
    assert.match(editor, /data-sticky-action-bar/);
    assert.match(shell, /getBoundingClientRect\(\)\.height/);
    assert.match(shell, /new ResizeObserver\(applyHeight\)/);
    assert.match(css, /padding-bottom: calc\(var\(--sticky-action-bar-height\) \+ var\(--space-4\)\)/);
    assert.doesNotMatch(css, /\.endpoint-editor \{ padding-bottom: 94px; \}/);
});

test('sticky action bar exposes one mutually exclusive status message', () => {
    assert.equal((editor.match(/id="endpoint-action-status"/g) ?? []).length, 1);
    assert.doesNotMatch(editor, /class="save-reason"/);
    assert.match(editor, /\$statusMessage = \$saveBlockReason/);
});

test('section tabs support neutral, attention, and valid states', () => {
    for (const state of ['neutral', 'attention', 'valid']) {
        assert.match(css, new RegExp(`\\.section-status\\.${state}`));
    }
    assert.match(editor, /Request not yet reviewed/);
    assert.match(editor, /Matching policy not yet reviewed/);
    assert.match(editor, /Response not yet reviewed/);
});
