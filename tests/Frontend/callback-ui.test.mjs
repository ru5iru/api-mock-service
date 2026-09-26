import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const editor = read('resources/views/livewire/admin/response-manager.blade.php');
const log = read('resources/views/livewire/admin/callback-log.blade.php');
const dashboard = read('resources/views/layouts/dashboard.blade.php');
const guide = read('docs/UI_GUIDE.md');

test('callback editor uses existing disclosure and documents raw-body HMAC signing', () => {
    assert.match(editor, /<details class="history-disclosure callback-disclosure">/);
    assert.match(editor, /data-template-editor-root/);
    assert.match(editor, /setCallbackEditorView\('builder'\)/);
    assert.match(editor, /schemaModel' => 'callbackBuilderSchema'/);
    assert.match(read('resources/views/livewire/admin/partials/schema-row.blade.php'), /data-schema-target/);
    assert.match(editor, /Sign requests/);
    assert.match(editor, /HMAC-SHA256 of the exact raw resolved body bytes/);
    assert.match(editor, /type="password"/);
    assert.match(editor, /Send test callback/);
    assert.match(guide, /Callback editor and callback log/);
});

test('callback log reuses request table, links triggering request and allows labelled resend', () => {
    assert.match(log, /class="table-scroll log-table-scroll"/);
    assert.match(log, /class="log-table"/);
    assert.match(log, /Request log/);
    assert.match(log, /aria-label="Resend callback attempt/);
    assert.match(dashboard, /dashboard\.callbacks\.index/);
});

test('documentation includes callback workflow and sensitive target warning', () => {
    assert.match(read('resources/views/admin/docs.blade.php'), /id="async-callbacks"/);
    assert.match(read('docs/USER_GUIDE.md'), /not.*SSRF-hardened/);
    assert.match(read('docs/VALIDATION.md'), /Callback smoke check/);
});

test('Blade displays the environment token example without interpreting it as an expression', () => {
    assert.match(editor, /@verbatim\{\{env\.KEY\}\}@endverbatim/);
    assert.match(read('resources/views/admin/docs.blade.php'), /@verbatim\{\{env\.KEY\}\}@endverbatim/);
});
