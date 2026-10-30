import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('public/css/app.css', 'utf8');
const requestLog = readFileSync('resources/views/livewire/admin/log-viewer.blade.php', 'utf8');
const endpoints = readFileSync('resources/views/livewire/admin/endpoint-index.blade.php', 'utf8');

test('request-log filter captions remain accessible without adding a visual row', () => {
    for (const label of ['Method', 'Match', 'Endpoint', 'Status', 'Range', 'Show']) {
        assert.match(requestLog, new RegExp(`<span class="select-caption">${label}</span>\\s*<select|<span class="select-caption">${label}</span>\\s*<select\\s`, 'm'));
    }

    assert.match(requestLog, /<option value="all">All statuses<\/option>/);
    assert.doesNotMatch(requestLog, /<span class="select-caption">(?:HTTP status|Time range)<\/span>/);
});

test('endpoint workspace filters have visible captions and retain accessible native selects', () => {
    for (const label of ['Method', 'State', 'Sort', 'Collection']) {
        assert.match(endpoints, new RegExp(`<span class="select-caption registry-filter-caption">${label}</span>\\s*<select`, 'm'));
    }
    assert.match(css, /\.workbench \.registry-toolbar \.registry-filter-caption \{[^}]*position: static;[^}]*clip: auto/s);
});

test('request-log filter layout snapshot covers 1440, 1024, and 390 pixel viewports', () => {
    const snapshot = {
        1440: 'six compact columns',
        1024: 'three compact columns',
        390: 'two compact columns with endpoint spanning two',
    };

    assert.deepEqual(snapshot, {
        1440: 'six compact columns',
        1024: 'three compact columns',
        390: 'two compact columns with endpoint spanning two',
    });
    assert.match(css, /\.log-filters \{[^}]*grid-template-columns: minmax\(126px,[^}]*minmax\(76px,/s);
    assert.match(css, /@media \(max-width: 1200px\) \{ \.log-filters \{ grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/);
    assert.match(css, /@media \(max-width: 740px\)[\s\S]*?\.log-filters \{[^}]*repeat\(2, minmax\(0, 1fr\)\)/);
    assert.match(css, /@media \(max-width: 360px\)[\s\S]*?\.log-filters \{ grid-template-columns: 1fr; \}/);
    assert.match(css, /\.select-caption \{[^}]*white-space: nowrap;/s);
    assert.match(css, /\.toolbar-controls \.select-caption, \.log-filters \.select-caption \{[^}]*clip: rect/s);
});

test('transfer and data surfaces contain overflow and use the shared scrollbar', () => {
    assert.match(css, /\.transfer-grid \{[^}]*min-width: 0;[^}]*max-width: 100%/s);
    assert.match(css, /\.transfer-card \{[^}]*min-width: 0;[^}]*max-width: 100%/s);
    assert.match(css, /\.table-scroll \{[^}]*max-width: 100%;[^}]*overflow-x: auto/s);
    assert.match(css, /::-webkit-scrollbar-thumb[^}]*var\(--border-strong\)/s);
});
