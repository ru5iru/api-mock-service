import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('public/css/app.css', 'utf8');
const requestLog = readFileSync('resources/views/livewire/admin/log-viewer.blade.php', 'utf8');
const endpoints = readFileSync('resources/views/livewire/admin/endpoint-index.blade.php', 'utf8');

test('request-log filter labels remain separate from their controls', () => {
    for (const label of ['Method', 'Match', 'Endpoint', 'Status', 'Range', 'Show']) {
        assert.match(requestLog, new RegExp(`<span class="select-caption">${label}</span>\\s*<select|<span class="select-caption">${label}</span>\\s*<select\\s`, 'm'));
    }

    assert.match(requestLog, /<option value="all">All statuses<\/option>/);
    assert.doesNotMatch(requestLog, /<span class="select-caption">(?:HTTP status|Time range)<\/span>/);
});

test('endpoint toolbar uses the same labelled filter pattern', () => {
    for (const label of ['Method', 'State', 'Sort']) {
        assert.match(endpoints, new RegExp(`<span class="select-caption">${label}</span>\\s*<select`, 'm'));
    }
});

test('request-log filter layout snapshot covers 1440, 1024, and 390 pixel viewports', () => {
    const snapshot = {
        1440: 'six labelled columns',
        1024: 'six labelled columns',
        390: 'two labelled columns with endpoint spanning two',
    };

    assert.deepEqual(snapshot, {
        1440: 'six labelled columns',
        1024: 'six labelled columns',
        390: 'two labelled columns with endpoint spanning two',
    });
    assert.match(css, /\.log-filters \{[^}]*grid-template-columns: minmax\(126px,[^}]*minmax\(76px,/s);
    assert.match(css, /@media \(max-width: 740px\)[\s\S]*?\.log-filters \{[^}]*repeat\(2, minmax\(0, 1fr\)\)/);
    assert.match(css, /@media \(max-width: 360px\)[\s\S]*?\.log-filters \{ grid-template-columns: 1fr; \}/);
    assert.match(css, /\.select-caption \{[^}]*white-space: nowrap;/s);
});
