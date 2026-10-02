// Dedicated database only: seed fixtures, then exercise Wave 1 controls in both themes.
// MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/wave1-smoke.mjs
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
        const settings = page.locator('.selection-settings');
        const rows = page.locator('.selection-response-card');
        const row = id => page.locator(`[wire\\:key="response-${id}"]`);
        const commit = async action => {
            await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST'), action()]);
        };
        // Regression: sticky Save changes previously submitted only EndpointForm and lost response drafts.
        await commit(() => row(fixture.first).getByRole('button', { name: 'Edit', exact: true }).click());
        await page.locator('#response-body').fill('{"response":"saved globally"}');
        const globalSave = async () => {
            await page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).click();
            await poll(async () => await page.locator('.response-form h3').innerText() === 'Add response', 'Global save did not finish response save/navigation');
        };
        await globalSave();
        await page.reload();
        await poll(async () => (await row(fixture.first).innerText()).includes('saved globally'), 'Global save lost the edited response');
        assert.equal(await rows.count(), 2, 'Global save created an unwanted response');
        await commit(() => row(fixture.first).getByRole('button', { name: 'Edit', exact: true }).click());
        await page.locator('#response-body').fill('{"response":"first"}');
        await page.locator('#response-weight').fill('0');
        await page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).click();
        await poll(async () => (await page.locator('.response-form [role="alert"]').innerText()).includes('not saved'), 'Global save failed without visible validation');
        assert.equal(await page.locator('#response-body').inputValue(), '{"response":"first"}', 'Invalid draft was lost');
        await poll(() => page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).isEnabled(), 'Global save stayed disabled after validation failure');
        await page.locator('#response-weight').fill('1');
        await globalSave();
        await page.reload();

        await page.close();
        console.log(`${theme}: global response save, reload persistence, no duplicate creation, validation and retry pass`);
    }
    assert.deepEqual(failures, [], 'Browser reported JavaScript errors');
} finally { await browser.close(); }
