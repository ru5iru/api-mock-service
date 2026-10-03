import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');
function* filesIn(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) yield* filesIn(path);
        else if (entry.isFile()) yield path;
    }
}

test('every application confirmation uses the shared dialog rather than a browser dialog', () => {
    for (const root of ['app', 'resources/views', 'public/js']) {
        for (const path of filesIn(root)) {
            if (!/\.(php|js)$/.test(path)) continue;
            assert.doesNotMatch(read(path), /\b(?:window\.)?(?:confirm|alert|prompt)\s*\(|wire:confirm/, path);
        }
    }
    const sites = ['response-manager', 'environment-manager', 'revision-history', 'config-transfer', 'endpoint-index'];
    assert.equal(sites.reduce((count, site) => count + (read(`resources/views/livewire/admin/${site}.blade.php`).match(/data-confirm="/g) ?? []).length, 0), 8);
    assert.match(read('resources/views/layouts/dashboard.blade.php'), /await window\.MockDeck\.ask/);
});

test('shared confirmation guards one replay and restores focus without scrolling', () => {
    const script = read('public/js/dialogs.js');
    assert.match(script, /approved\.delete\(trigger\)/);
    assert.match(script, /if \(accepted && trigger\.isConnected && !trigger\.disabled\)/);
    assert.match(script, /trigger\.focus\(\{ preventScroll: true \}\)/);
    assert.match(script, /dialog\.returnValue === 'confirmed'/);
    assert.match(script, /\[data-confirm-cancel\]'.*focus\(\)/);
    assert.match(script, /trigger\.matches\('\.danger, \.danger-text, \.button-danger'\)/);
    assert.match(read('public/js/menu.js'), /if \(event\.target\.closest\?\.\('dialog\[open\]'\)\) return;/);
});
