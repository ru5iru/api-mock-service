import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
const read = path => readFileSync(path, 'utf8');
function* files(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) yield* files(path);
        else if (entry.isFile()) yield path;
    }
}
test('all native selects and disclosures use the shared recipes', () => {
    for (const path of files('resources/views')) {
        const view = read(path);
        for (const select of view.matchAll(/<select\b[^>]*>/g)) assert.match(select[0], /class="ui-select(?: |")/, path);
        for (const details of view.matchAll(/<details\b[^>]*>/g)) assert.match(details[0], /data-menu|data-disclosure/, path);
    }
    assert.match(read('resources/views/livewire/admin/endpoint-index.blade.php'), /links\('components.pagination'\)/);
    assert.match(read('resources/views/components/pagination.blade.php'), /previousPage/);
    assert.match(read('resources/views/components/pagination.blade.php'), /nextPage/);
    assert.match(read('resources/views/components/pagination.blade.php'), /gotoPage/);
});
test('selects and custom menu chevrons share one theme-aware affordance', () => {
    const css = read('public/css/app.css');
    assert.match(css, /select\.ui-select[^}]+background-image: var\(--select-chevron\)/s);
    assert.match(css, /\.control-chevron[^}]+background-image: var\(--select-chevron\)/s);
    for (const path of ['resources/views/livewire/admin/environment-switcher.blade.php', 'resources/views/livewire/admin/partials/schema-row.blade.php']) {
        assert.match(read(path), /<x-chevron/);
        assert.doesNotMatch(read(path), /⌄/);
    }
    for (const path of ['resources/views/auth/login.blade.php', 'resources/views/components/layouts/error.blade.php']) assert.match(read(path), /js\/panels\.js/);
});
test('save and morph states follow the documented component contracts', () => {
    const environments = read('resources/views/livewire/admin/environment-manager.blade.php');
    assert.match(environments, /button-primary[^>]+renameSelected/);
    assert.match(environments, /button-primary button-small[^>]+saveVariable/);
    assert.match(read('public/js/menu.js'), /attributeFilter: \['open', 'aria-expanded', 'aria-haspopup'\]/);
    assert.match(read('resources/views/layouts/dashboard.blade.php'), /attributeFilter: \['open', 'aria-expanded'\]/);
    assert.match(read('public/css/app.css'), /\.field input\.sr-only[^}]+width: 1px;[^}]+min-height: 0/s);
});
