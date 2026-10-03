import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');
const guide = read('docs/UI_GUIDE.md');
const css = read('public/css/tokens.css');
const parse = value => Object.fromEntries([...value.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map(match => [match[1], match[2].trim()]));
const split = css.indexOf('[data-theme="dark"]');
const light = parse(css.slice(0, split));
const dark = { ...light, ...parse(css.slice(split)) };

test('component reference documents every resolved light/dark token exactly', () => {
    assert.equal(guide.includes('TOKEN_TABLE'), false);
    for (const [name, value] of Object.entries(light)) {
        const escape = text => text.replaceAll('|', '\\|');
        const expected = `| \`${name}\` | \`${escape(value)}\` | ${dark[name] === value ? 'Same' : '\`' + escape(dark[name]) + '\`'} |`;
        assert.ok(guide.includes(expected), `${name}: resolved values drifted`);
    }
});

test('component reference paths and class vocabulary resolve to real sources', () => {
    for (const match of guide.matchAll(/`((?:resources|public|scripts|tests)\/[\w./-]+)`/g)) {
        assert.ok(existsSync(match[1]), `Missing source ${match[1]}`);
    }
    const styles = read('public/css/app.css') + read('resources/views/layouts/dashboard.blade.php');
    const views = read('resources/views/livewire/admin/log-viewer.blade.php') + read('resources/views/livewire/admin/callback-log.blade.php');
    for (const match of guide.matchAll(/`\.([a-z][a-z0-9-]*)/g)) {
        assert.ok(styles.includes(`.${match[1]}`) || new RegExp(`class="[^"]*\\b${match[1]}\\b`).test(views), `Missing class .${match[1]}`);
    }
    assert.match(guide, /does \*\*not\*\* validate/);
    assert.match(read('public/js/panels.js'), /Math\.max\(edge/);
});
