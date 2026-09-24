import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const themeSource = readFileSync('public/js/theme.js', 'utf8');
const preferencesSource = readFileSync('public/js/ui-preferences.js', 'utf8');

function themeEnvironment({ stored = null, prefersDark = false, storageUnavailable = false } = {}) {
    const storedValues = new Map(stored ? [['mockdeck-theme', stored]] : []);
    const media = {
        matches: prefersDark,
        listener: null,
        addEventListener(_event, listener) { this.listener = listener; },
    };
    const meta = { content: '', setAttribute(_name, value) { this.content = value; } };
    const root = {
        dataset: {},
        style: {},
        classList: { add() {}, remove() {} },
    };
    const storage = {
        getItem(key) {
            if (storageUnavailable) throw new Error('storage unavailable');
            return storedValues.get(key) ?? null;
        },
        setItem(key, value) {
            if (storageUnavailable) throw new Error('storage unavailable');
            storedValues.set(key, value);
        },
        removeItem(key) {
            if (storageUnavailable) throw new Error('storage unavailable');
            storedValues.delete(key);
        },
    };
    const document = {
        documentElement: root,
        querySelector(selector) { return selector === 'meta[name="theme-color"]' ? meta : null; },
        querySelectorAll() { return []; },
        addEventListener() {},
    };
    const window = {
        localStorage: storage,
        matchMedia() { return media; },
        requestAnimationFrame(callback) { callback(); },
        dispatchEvent() {},
    };
    const context = {
        window,
        document,
        CustomEvent: class CustomEvent {},
        getComputedStyle() { return { getPropertyValue() { return '#14120f'; } }; },
    };

    vm.runInNewContext(themeSource, context);

    return { media, root, storedValues, window };
}

test('theme resolves persisted Light and Dark choices', () => {
    assert.equal(themeEnvironment({ stored: 'light', prefersDark: true }).root.dataset.theme, 'light');
    assert.equal(themeEnvironment({ stored: 'dark', prefersDark: false }).root.dataset.theme, 'dark');
});

test('System theme follows live OS changes', () => {
    const environment = themeEnvironment({ prefersDark: false });
    assert.equal(environment.root.dataset.themeMode, 'system');
    assert.equal(environment.root.dataset.theme, 'light');

    environment.media.matches = true;
    environment.media.listener();
    assert.equal(environment.root.dataset.theme, 'dark');
});

test('theme remains usable when storage is unavailable', () => {
    const environment = themeEnvironment({ storageUnavailable: true, prefersDark: true });
    assert.equal(environment.root.dataset.theme, 'dark');
    assert.doesNotThrow(() => environment.window.MockDeckTheme.setMode('light'));
    assert.equal(environment.root.dataset.theme, 'light');
});

test('explicit theme choices persist and System clears the override', () => {
    const environment = themeEnvironment();
    environment.window.MockDeckTheme.setMode('dark');
    assert.equal(environment.storedValues.get('mockdeck-theme'), 'dark');
    environment.window.MockDeckTheme.setMode('system');
    assert.equal(environment.storedValues.has('mockdeck-theme'), false);
});

test('theme resolver is loaded before styles on every standalone shell', () => {
    for (const path of [
        'resources/views/layouts/dashboard.blade.php',
        'resources/views/auth/login.blade.php',
        'resources/views/components/layouts/error.blade.php',
    ]) {
        const source = readFileSync(path, 'utf8');
        assert.ok(source.includes('meta name="color-scheme"'), `${path} declares color-scheme`);
        assert.ok(source.indexOf("js/theme.js") < source.indexOf("css/tokens.css"), `${path} resolves theme before CSS`);
    }
});

function preferenceEnvironment({ stored = null, storageUnavailable = false } = {}) {
    const storedValues = new Map(stored ? [['mockdeck:log-density', stored]] : []);
    const viewer = { dataset: { logDensity: 'compact' } };
    const buttons = ['compact', 'comfortable'].map((value) => ({
        dataset: { densityOption: value },
        attributes: new Map([['aria-pressed', value === 'compact' ? 'true' : 'false']]),
        getAttribute(name) { return this.attributes.get(name) ?? null; },
        setAttribute(name, value) { this.attributes.set(name, value); },
    }));
    const storage = {
        getItem(key) {
            if (storageUnavailable) throw new Error('storage unavailable');
            return storedValues.get(key) ?? null;
        },
        setItem(key, value) {
            if (storageUnavailable) throw new Error('storage unavailable');
            storedValues.set(key, value);
        },
    };
    const document = {
        readyState: 'complete',
        body: {},
        querySelectorAll(selector) {
            if (selector === '[data-log-viewer]') return [viewer];
            if (selector === '[data-density-option]') return buttons;
            return [];
        },
        addEventListener() {},
    };
    const window = { localStorage: storage };
    const context = {
        window,
        document,
        MutationObserver: class MutationObserver { observe() {} },
    };

    vm.runInNewContext(preferencesSource, context);

    return { buttons, storedValues, viewer, window };
}

test('request-log density restores and persists safely', () => {
    const environment = preferenceEnvironment({ stored: 'comfortable' });
    assert.equal(environment.viewer.dataset.logDensity, 'comfortable');
    assert.equal(environment.buttons[1].getAttribute('aria-pressed'), 'true');

    environment.window.MockDeckPreferences.setLogDensity('compact');
    assert.equal(environment.viewer.dataset.logDensity, 'compact');
    assert.equal(environment.storedValues.get('mockdeck:log-density'), 'compact');
});

test('request-log density falls back when storage is unavailable', () => {
    const environment = preferenceEnvironment({ storageUnavailable: true });
    assert.equal(environment.viewer.dataset.logDensity, 'compact');
    assert.doesNotThrow(() => environment.window.MockDeckPreferences.setLogDensity('comfortable'));
    assert.equal(environment.viewer.dataset.logDensity, 'comfortable');
});
