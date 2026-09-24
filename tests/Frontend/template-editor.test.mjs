import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/js/template-editor.js', 'utf8');
const view = readFileSync('resources/views/livewire/admin/response-manager.blade.php', 'utf8');
const row = readFileSync('resources/views/livewire/admin/partials/schema-row.blade.php', 'utf8');
const css = readFileSync('public/css/app.css', 'utf8');
const guide = readFileSync('docs/UI_GUIDE.md', 'utf8');

test('JSON template autocomplete is catalog-backed and keyboard operable', () => {
    assert.match(source, /fetch\('\/api\/faker-catalog'/);
    assert.match(source, /event\.key === 'ArrowDown'/);
    assert.match(source, /event\.key === 'Enter' \|\| event\.key === 'Tab'/);
    assert.match(source, /event\.key === 'Escape'/);
    assert.match(source, /positionPopover\(editor, popover\)/);
    assert.match(view, /wire:model\.live\.debounce\.400ms="template"/);
    assert.match(view, /data-template-autocomplete role="listbox"/);
});

test('builder exposes grouped methods, argument fields, pointer and keyboard reordering', () => {
    assert.match(row, /groupBy\('module'\)/);
    assert.match(row, /data-faker-search/);
    assert.match(row, /data-faker-option/);
    assert.match(row, /args_options/);
    assert.match(row, /data-schema-drag-handle/);
    assert.match(row, /aria-label="Move field up"/);
    assert.match(row, /aria-label="Move field down"/);
    assert.match(source, /reorderSchemaRow/);
    assert.match(source, /positionFakerPicker/);
    assert.match(source, /details\[data-faker-picker\]\[open\]/);
});

test('new editor patterns are tokenized and recorded in the UI guide', () => {
    assert.match(css, /\.segmented-control/);
    assert.match(css, /\.faker-picker-panel[^}]*var\(--surface-raised\)/s);
    assert.match(css, /\.template-autocomplete[^}]*var\(--surface-raised\)/s);
    assert.match(guide, /### Faker method picker/);
    assert.match(guide, /### JSON template autocomplete/);
    assert.match(guide, /\.segmented-control/);
});
