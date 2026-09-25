import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('environment switcher and tag chips reuse documented UI primitives', () => {
    const guide = read('docs/UI_GUIDE.md');
    const layout = read('resources/views/layouts/dashboard.blade.php');
    const switcher = read('resources/views/livewire/admin/environment-switcher.blade.php');
    const endpointIndex = read('resources/views/livewire/admin/endpoint-index.blade.php');

    assert.match(guide, /### Environment switcher/);
    assert.match(guide, /`\.tag-chip`/);
    assert.match(layout, /livewire:admin\.environment-switcher/);
    assert.match(layout, /data-environment-option/);
    assert.match(switcher, /role="menuitemradio"/);
    assert.match(endpointIndex, /class="tag-chip selectable/);
    assert.match(endpointIndex, /bulkMoveToCollection/);
    assert.match(endpointIndex, /bulkAddTags/);
});

test('environment management keeps secrets masked and documents inheritance', () => {
    const manager = read('resources/views/livewire/admin/environment-manager.blade.php');
    const editor = read('resources/views/livewire/admin/endpoint-form.blade.php');
    const transfer = read('resources/views/livewire/admin/config-transfer.blade.php');

    assert.match(manager, /leave blank to keep/);
    assert.match(manager, /'password'/);
    assert.match(editor, /All other environments/);
    assert.match(editor, /Inherit endpoint state/);
    assert.match(transfer, /Inherited endpoints are included/);
});
