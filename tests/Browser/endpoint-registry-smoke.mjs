// Use a dedicated fixture database: this test creates endpoints/tags and changes assignments.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'endpoint-registry');
await mkdir(shots, { recursive: true });
async function poll(check, message) {
    const end = Date.now() + 12000;
    while (Date.now() < end) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
const browser = await chromium.launch({ headless: true });
const errors = [];
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/endpoint-registry-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', error => errors.push(`${theme}: ${error.message}`));
        await page.addInitScript(theme => localStorage.setItem('mockdeck-theme', theme), theme);
        await page.goto(`${base}/dashboard`);
        const cards = page.locator('.endpoint-card');
        await poll(async () => await cards.count() === 5, 'Fixture endpoint list missing');
        const filter = page.locator('.tag-filter-picker');
        await filter.locator('summary').click();
        assert.equal(await filter.getByLabel('Filter by tag Unused draft tag', { exact: true }).count(), 0);
        assert.match(await filter.innerText(), /2 endpoints/);
        await filter.locator('label').filter({ has: page.getByLabel('Filter by tag abc', { exact: true }) }).click();
        await poll(async () => await cards.count() === 2 && (await filter.locator('summary').innerText()).includes('1 selected'), 'Tag filter did not select the assigned endpoints');
        await page.keyboard.press('Escape');
        assert.equal(await filter.evaluate(el => el.open), false);
        assert.equal(await filter.locator('summary').evaluate(el => el === document.activeElement), true);
        await filter.locator('summary').click();
        await filter.getByRole('button', { name: 'Clear tags', exact: true }).click();
        await poll(async () => await cards.count() === 5, 'Clear tags did not restore endpoints');
        await page.keyboard.press('Escape');
        assert.match(await page.locator('.endpoint-body-size').last().innerText(), /1,048,576 B/);

        for (const width of [1680, 1440, 1200, 1024, 901, 900, 768, 740, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            const selectors = ['.endpoint-header-count', '.endpoint-body-size', '.canonical-popover > summary', '.metadata-row > .ui-badge', '.endpoint-response-count', '.endpoint-signature-version', '.endpoint-updated'];
            const geometry = await cards.evaluateAll((rows, selectors) => rows.map(row => {
                const box = row.getBoundingClientRect();
                return { left: box.left, right: box.right, cells: selectors.map(selector => {
                    const el = row.querySelector(selector), bounds = el.getBoundingClientRect();
                    return { x: bounds.left, right: bounds.right, width: bounds.width, text: el.textContent.trim() };
                }) };
            }), selectors);
            for (let col = 0; col < selectors.length; col++) {
                for (const row of geometry) {
                    assert.ok(Math.abs(row.cells[col].x - geometry[0].cells[col].x) <= 1, `${theme}/${width}: ${selectors[col]} shifts between rows`);
                    assert.ok(row.cells[col].x >= row.left && row.cells[col].right <= row.right + 1, `${theme}/${width}: ${selectors[col]} escapes its card`);
                }
            }
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${theme}/${width}: page overflows horizontally`);
            await filter.locator('summary').scrollIntoViewIfNeeded();
            await filter.locator('summary').click();
            await poll(async () => {
                const box = await filter.locator(':scope > div').boundingBox();
                return box && box.x >= 0 && box.x + box.width <= width + 1;
            }, `${theme}/${width}: tag menu escapes viewport`);
            await page.keyboard.press('Escape');
            await cards.first().locator('.canonical-popover > summary').click();
            await poll(async () => {
                const box = await cards.first().locator('.canonical-popover pre').boundingBox();
                return box && box.x >= 0 && box.x + box.width <= width + 1;
            }, `${theme}/${width}: canonical panel escapes viewport`);
            await page.keyboard.press('Escape');
            if ([1440, 1024, 390, 320].includes(width)) await page.screenshot({ path: resolve(shots, `${theme}-${width}.png`) });
        }

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoints[0]}/edit`);
        const chip = page.locator('.tag-input .tag-chip').filter({ has: page.locator(`input[value="${fixture.tag}"]`) });
        const checkbox = chip.locator('input');
        assert.equal(await checkbox.isChecked(), true);
        await page.mouse.move(2, 2);
        const selectedColor = await chip.evaluate(el => getComputedStyle(el).backgroundColor);
        await chip.click();
        assert.equal(await checkbox.isChecked(), false);
        await page.mouse.move(2, 2);
        assert.notEqual(await chip.evaluate(el => getComputedStyle(el).backgroundColor), selectedColor, 'Deferred tag draft left a stale selected highlight');
        const editorId = await page.locator('.endpoint-editor').getAttribute('wire:id');
        await page.locator('#save-actions button[type=submit]').click();
        await poll(async () => (await page.locator('.endpoint-editor').getAttribute('wire:id')) !== editorId, 'Tag draft did not save');
        await page.goto(`${base}/dashboard`);
        await filter.locator('summary').click();
        assert.match(await filter.innerText(), /1 endpoint/);
        await filter.locator('label').filter({ has: page.getByLabel('Filter by tag abc', { exact: true }) }).click();
        await poll(async () => await cards.count() === 1, 'Saved tag assignment count is incorrect');
        await page.close();
        console.log(`${theme}: tag filtering/counts, draft styling/save, 0–1,048,576 body bytes, 0–128 headers, 1–101 responses, and aligned cells at 10 widths pass`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
