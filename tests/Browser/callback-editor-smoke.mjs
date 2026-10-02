// Dedicated database only: exercise callback configuration in both themes.
// MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/callback-editor-smoke.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
const runtimeModules = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const require = runtimeModules ? createRequire(`${runtimeModules}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Set MOCKDECK_BASE_URL and MOCKDECK_BROWSER_FIXTURE=1, using a dedicated test database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const failures = [];
async function poll(check, message) {
    const deadline = Date.now() + 10000;
    while (Date.now() < deadline) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/wave1-browser-fixture.php'], {
            env: process.env, encoding: 'utf8',
        }));
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', error => failures.push(`${theme}: ${error.message}`));
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        const row = id => page.locator(`[wire\\:key="response-${id}"]`);
        const commit = async action => {
            await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST'), action()]);
        };
        const callback = page.locator('.callback-disclosure');
        await commit(() => row(fixture.first).getByRole('button', { name: `Configure callback for response ${fixture.first}` }).click());
        await poll(() => callback.evaluate(el => el.open), 'Callback action did not open configuration');
        await commit(() => callback.getByRole('checkbox', { name: 'Enable callback' }).check());
        assert.equal(await callback.evaluate(el => el.open), true, 'Enabling callback collapsed configuration');
        await page.locator('#callback-url').fill('https://receiver.test/hook');
        await page.locator('#callback-method').selectOption('PATCH');
        await page.locator('#callback-body').fill('{"event":"created"}');
        await commit(() => page.locator('.response-form').getByRole('button', { name: 'Update response', exact: true }).click());
        await poll(async () => await page.locator('.response-form h3').innerText() === 'Add response', 'Callback configuration was not saved');
        await page.reload();
        await commit(() => row(fixture.first).getByRole('button', { name: `Configure callback for response ${fixture.first}` }).click());
        await poll(() => callback.evaluate(el => el.open), 'Saved callback did not reopen');
        assert.equal(await page.locator('#callback-url').inputValue(), 'https://receiver.test/hook');
        assert.equal(await page.locator('#callback-method').inputValue(), 'PATCH');
        assert.equal(await page.locator('#callback-body').inputValue(), '{"event":"created"}');
        assert.equal(await callback.getByRole('checkbox', { name: 'Enable callback' }).isChecked(), true);
        for (const width of [1440, 1024, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.locator('#callback-url').scrollIntoViewIfNeeded();
            assert.equal(await page.locator('#callback-url').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${theme}/${width}: horizontal overflow`);
        }
        await page.screenshot({ path: resolve(shots, `callback-editor-${theme}.png`), fullPage: true });
        await page.close();
        console.log(`${theme}: callback action, disclosure persistence, save/reload, desktop/tablet/mobile visibility pass`);
    }
    assert.deepEqual(failures, [], 'Browser reported JavaScript errors');
} finally { await browser.close(); }
