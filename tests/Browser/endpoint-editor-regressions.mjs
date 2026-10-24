// Disposable database only. Never sends an outbound callback.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
if (process.env.MOCKDECK_BROWSER_FIXTURE !== '1' || !process.env.MOCKDECK_BASE_URL) throw Error('Use a disposable browser database and MOCKDECK_BASE_URL.');
const base = process.env.MOCKDECK_BASE_URL;
const directory = process.env.MOCKDECK_SCREENSHOT_DIR || 'tests/Browser/screenshots/endpoint-editor';
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
async function wait(check, message) {
    const until = Date.now() + 10000;
    while (Date.now() < until) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 60)); }
    throw Error(message);
}
try {
    for (const theme of ['light', 'dark']) for (const width of [390, 740, 1440]) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP_BIN || 'php', ['tests/Support/endpoint-editor-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const [first] = fixture.responses;
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        const editUrl = `${base}/dashboard/endpoints/${fixture.endpoint}/edit`;
        await page.goto(editUrl); await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme); await page.reload();
        await wait(() => page.evaluate(() => Boolean(window.MockDeckEditor)), 'Draft controller did not initialize');
        assert.equal(await page.evaluate(() => window.MockDeckEditor.isDirty()), false, 'Fresh editor must be clean');
        for (const item of await page.getByRole('tab').all()) assert.ok((await item.boundingBox()).height >= 44);
        const tip = page.locator('.help-tip[aria-label="Priority"]'); await tip.focus();
        const description = await tip.getAttribute('aria-describedby');
        assert.ok(description); assert.equal(await page.locator(`[id="${description}"]`).getAttribute('role'), 'tooltip');
        await page.keyboard.press('Escape');
        const tab = name => page.getByRole('tab', { name: new RegExp(`^${name}`) }).click();
        await tab('Response'); await page.getByRole('button', { name: 'Sequence', exact: true }).click();
        await wait(async () => (await page.locator('.selection-settings').innerText()).includes('Unsaved selection changes.'), 'Selection did not become dirty');
        await page.locator('#sequence-on-exhaust').selectOption('loop');
        await page.locator('.selection-response-card').filter({ hasText: `Response #${first}` }).getByRole('button', { name: 'Edit', exact: true }).click();
        await wait(async () => (await page.locator('.response-form h3').innerText()).includes(`#${first}`), 'Response edit did not load');
        await page.locator('#response-body').fill('{"changed":true}');
        await page.getByRole('button', { name: 'Update response', exact: true }).click();
        await wait(async () => (await page.locator('.response-form h3').innerText()) === 'Add response', 'Response update did not finish');
        assert.equal(await page.getByRole('button', { name: 'Sequence', exact: true }).getAttribute('aria-pressed'), 'true');
        assert.equal(await page.locator('#sequence-on-exhaust').inputValue(), 'loop');
        assert.ok((await page.locator('.selection-settings').innerText()).includes('Unsaved selection changes.'));
        await page.getByRole('button', { name: 'Save selection', exact: true }).click();
        await wait(async () => !(await page.locator('.selection-settings').innerText()).includes('Unsaved selection changes.'), 'Selection save did not finish');
        assert.ok((await page.locator('.selection-preview h3').innerText()).includes('Saved'));
        await tab('Callback'); await page.locator(`button[wire\\:click="editCallback(${first}, false)"]`).click();
        const callbackUrl = page.locator('#callback-url'); await callbackUrl.fill('https://changed.example.test/hook');
        const callbackTest = page.getByRole('button', { name: 'Test saved callback', exact: true });
        await wait(() => callbackTest.isDisabled(), 'Dirty callback testing must be disabled');
        await page.getByRole('tab', { name: /^Callback/ }).focus(); await page.keyboard.press('n');
        const confirm = page.locator('#confirm-dialog');
        await wait(() => confirm.evaluate(node => node.open), 'N bypassed confirmation');
        await confirm.locator('[data-confirm-cancel]').click();
        assert.equal(new URL(page.url()).pathname, new URL(editUrl).pathname);
        assert.equal(await callbackUrl.inputValue(), 'https://changed.example.test/hook');
        await page.getByRole('button', { name: 'Save callback', exact: true }).click();
        await wait(() => callbackTest.isEnabled(), 'Saved callback should be testable');
        // Deliberately do not click Test saved callback.
        await tab('Response'); await page.getByRole('button', { name: 'Template', exact: true }).click();
        const keys = page.locator('.response-form .schema-key input'); await keys.first().fill('id');
        await page.locator('.response-form').getByRole('button', { name: /Add field/ }).click();
        await keys.nth(1).fill(' id ');
        await wait(async () => await page.locator('.schema-key .field-error').count() === 2, 'Duplicate keys were not identified');
        assert.equal(await keys.count(), 2); await keys.nth(1).fill('other');
        await wait(async () => await page.locator('.schema-key .field-error').count() === 0, 'Corrected field did not recover');
        await page.getByRole('button', { name: 'JSON', exact: true }).click();
        await page.locator('#response-template').fill(JSON.stringify({ outer: { nested: { deep: { more: { value: 'long '.repeat(20) } } } } }));
        await wait(async () => (await page.locator('.response-manager').getAttribute('data-draft-current')).includes('outer'), 'Nested template did not reach the server');
        await page.getByRole('button', { name: 'Builder', exact: true }).click();
        for (const control of await page.locator('.response-form .schema-builder input, .response-form .schema-builder select, .response-form .schema-builder button').all()) {
            if (!await control.isVisible()) continue;
            const bounds = await control.boundingBox(); assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, 'Nested control escaped the viewport');
        }
        if (width === 1440) {
            await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Editor overflows at 200% CSS zoom');
            await page.evaluate(() => { document.documentElement.style.zoom = ''; });
        }
        await page.screenshot({ path: `${directory}/${theme}-${width}.png`, fullPage: true });
        assert.deepEqual(errors, []); await page.close();
    }
} finally { await browser.close(); }
