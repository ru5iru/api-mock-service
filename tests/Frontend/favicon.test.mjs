import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const shells = [
    'resources/views/layouts/dashboard.blade.php',
    'resources/views/auth/login.blade.php',
    'resources/views/components/layouts/error.blade.php',
];

function pngSize(path) {
    const image = readFileSync(path);

    assert.equal(image.subarray(1, 4).toString(), 'PNG', `${path} is a PNG`);

    return [image.readUInt32BE(16), image.readUInt32BE(20)];
}

function icoSizes(path) {
    const icon = readFileSync(path);
    const count = icon.readUInt16LE(4);
    const sizes = [];

    for (let index = 0; index < count; index += 1) {
        const offset = 6 + (index * 16);
        sizes.push(icon[offset] || 256);
    }

    return sizes.sort((first, second) => first - second);
}

test('favicon component keeps the SVG primary and declares platform fallbacks', () => {
    const component = readFileSync('resources/views/components/favicon-links.blade.php', 'utf8');

    assert.ok(component.indexOf("favicon.ico") < component.indexOf("favicon.svg"), 'SVG is declared after the ICO fallback');
    assert.match(component, /favicon\.svg'\) \}\}" type="image\/svg\+xml" sizes="any"/);
    assert.match(component, /apple-touch-icon\.png/);
    assert.match(component, /site\.webmanifest/);

    for (const path of shells) {
        assert.match(readFileSync(path, 'utf8'), /<x-favicon-links \/>/, `${path} includes the shared icon links`);
    }
});

test('favicon artwork and platform icon sizes match the browser contracts', () => {
    const favicon = readFileSync('public/favicon.svg', 'utf8');

    assert.match(favicon, /prefers-color-scheme:\s*dark/);
    assert.match(favicon, /viewBox="0 0 64 64"/);
    assert.deepEqual(icoSizes('public/favicon.ico'), [16, 32, 48]);
    assert.deepEqual(pngSize('public/apple-touch-icon.png'), [180, 180]);
    assert.deepEqual(pngSize('public/icon-192.png'), [192, 192]);
    assert.deepEqual(pngSize('public/icon-512.png'), [512, 512]);
});

test('web app manifest exposes both PWA icons', () => {
    const manifest = JSON.parse(readFileSync('public/site.webmanifest', 'utf8'));

    assert.equal(manifest.name, 'MockDeck');
    assert.equal(manifest.start_url, '/dashboard');
    assert.deepEqual(
        manifest.icons.map(({ src, sizes, type }) => ({ src, sizes, type })),
        [
            { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
            { src: '/icon-512.png', sizes: '512x512', type: 'image/png' },
        ],
    );
});

test('in-app brand marks use the static icon matching the effective theme', () => {
    const component = readFileSync('resources/views/components/brand-mark.blade.php', 'utf8');
    const styles = readFileSync('public/css/app.css', 'utf8');

    assert.match(component, /brand-mark-light[^>]+favicon-light\.svg/);
    assert.match(component, /brand-mark-dark[^>]+favicon-dark\.svg/);
    assert.match(styles, /\[data-theme="dark"\] \.brand-mark-light \{ visibility: hidden; \}/);
    assert.match(styles, /\[data-theme="dark"\] \.brand-mark-dark \{ visibility: visible; \}/);

    for (const path of shells) {
        assert.match(readFileSync(path, 'utf8'), /<x-brand-mark \/>/, `${path} uses the shared themed brand mark`);
    }
});
