import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const editor = read('resources/views/livewire/admin/response-manager.blade.php');
const settings = read('resources/views/livewire/admin/partials/callback-settings.blade.php');
const log = read('resources/views/livewire/admin/callback-log.blade.php');
const dashboard = read('resources/views/layouts/dashboard.blade.php');
const guide = read('docs/UI_GUIDE.md');

test('callback editor lives in its own tab and documents raw-body HMAC signing', () => {
    assert.match(editor, /id="panel-callback" role="tabpanel"/);
    assert.doesNotMatch(editor, /callback-disclosure/);
    assert.match(settings, /data-template-editor-root/);
    assert.match(settings, /setCallbackEditorView\('builder'\)/);
    assert.match(settings, /schemaModel' => 'callbackBuilderSchema'/);
    assert.match(read('resources/views/livewire/admin/partials/schema-row.blade.php'), /data-schema-target/);
    assert.match(settings, /Sign requests/);
    assert.match(settings, /HMAC-SHA256 of the exact raw resolved body bytes/);
    assert.match(settings, /type="password"/);
    assert.match(settings, /Send test callback/);
    assert.match(guide, /Callback configuration belongs in the Callback tab/);
    assert.match(guide, /Owner: log viewer, callback log, endpoint form/);
});

test('callback log reuses request table, links triggering request and allows labelled resend', () => {
    assert.match(log, /class="table-scroll log-table-scroll"/);
    assert.match(log, /class="log-table"/);
    assert.match(log, /Request log/);
    assert.match(log, /aria-label="Resend callback attempt/);
    assert.match(dashboard, /dashboard\.logs\.index/);
    assert.match(read('resources/views/admin/logs.blade.php'), /aria-label="Log type"/);
    assert.match(log, /class="search-field log-search"/);
});

test('documentation includes callback workflow and sensitive target warning', () => {
    assert.match(read('resources/views/admin/docs.blade.php'), /id="async-callbacks"/);
    assert.match(read('docs/USER_GUIDE.md'), /not.*SSRF-hardened/);
    assert.match(read('docs/VALIDATION.md'), /Callback smoke check/);
});

test('Blade displays the environment token example without interpreting it as an expression', () => {
    assert.match(settings, /@verbatim\{\{env\.KEY\}\}@endverbatim/);
    assert.match(read('resources/views/admin/docs.blade.php'), /@verbatim\{\{env\.KEY\}\}@endverbatim/);
});
