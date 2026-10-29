import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(path, 'utf8');
test('both log types share bounded inspector structure and intentional focus', () => {
    for (const type of ['log-viewer', 'callback-log']) {
        const view = read(`resources/views/livewire/admin/${type}.blade.php`);
        assert.match(view, /data-activity-workspace/);
        assert.match(view, /data-activity-back/);
        assert.match(view, /no longer in the filtered window/);
    }
    const focus = read('public/js/activity-inspector.js');
    assert.match(focus, /if \(!pending\) return/);
    assert.match(focus, /livewire:navigating/);
    assert.doesNotMatch(focus, /localStorage|sessionStorage|fetch\(/);
});
test('Environment and Callback use continuous responsive workspaces', () => {
    const css = read('public/css/app.css');
    assert.match(css, /\.callback-workspace/);
    assert.match(css, /\.environment-variable-table td\[data-column\]::before/);
    const env = read('resources/views/livewire/admin/environment-manager.blade.php');
    assert.match(env, /Selected for editing/);
    assert.match(env, /leave blank to keep/);
});
test('endpoint History stays in its component while save bar retains teleport', () => {
    const editor = read('resources/views/livewire/admin/endpoint-form.blade.php');
    assert.doesNotMatch(editor, /@teleport\('body'\)\s*<x-dialog id="endpoint-history-dialog"/);
    assert.match(editor, /@teleport\('body'\)\s*<div id="save-actions"/);
});
