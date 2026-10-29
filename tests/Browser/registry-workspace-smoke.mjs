// Destructive fixture: run only against a fresh disposable database.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a fresh dedicated fixture database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'registry-workspace');
await mkdir(shots, { recursive: true });
const fixture = action => execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/registry-workspace-fixture.php', ...(action ? [action] : [])], { env: process.env, encoding: 'utf8' });
const saved = () => JSON.parse(fixture('inspect'));
async function poll(check, message) {
    const until = Date.now() + 15000;
    while (Date.now() < until) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
const errors = [];
try {
    for (const theme of ['light', 'dark']) {
        fixture('clear');
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] });
        const page = await context.newPage();
        page.on('pageerror', e => errors.push(`${theme}: ${e.message}`));
        await page.addInitScript(theme => localStorage.setItem('mockdeck-theme', theme), theme);
        await page.goto(`${base}/dashboard`);
        await page.locator('[data-registry-empty="database"]').waitFor();
        assert.equal(await page.getByRole('checkbox', { name: 'Select all endpoints on this page' }).isDisabled(), true);
        const data = JSON.parse(fixture());
        await page.reload();
        const rows = page.locator('.endpoint-card');
        const count = n => poll(async () => await rows.count() === n, `Expected ${n} endpoints`);
        const clear = async () => { await page.getByRole('button', { name: 'Clear filters', exact: true }).first().click(); await count(15); };
        const search = page.getByRole('searchbox', { name: 'Search endpoints', exact: true });
        await count(15);
        await page.getByLabel('Filter by method').selectOption('POST'); await count(9);
        await page.getByLabel('Filter by state').selectOption('disabled'); await count(9);
        await page.getByLabel('Filter by collection', { exact: true }).selectOption(String(data.collection)); await count(1);
        assert.match(await page.locator('.registry-list-heading').innerText(), /1 of 18 endpoints/);
        await clear();
        await page.getByLabel('Sort endpoints', { exact: true }).selectOption('priority');
        await poll(async () => (await rows.first().innerText()).includes('Registry workspace 18'), 'Priority sort not applied');
        await page.getByLabel('Sort endpoints', { exact: true }).selectOption('name');
        await poll(async () => (await rows.first().innerText()).includes('Registry workspace 01'), 'Name sort not applied');
        await page.getByLabel('Filter by collection', { exact: true }).selectOption(String(data.empty));
        await page.locator('[data-registry-empty="collection"]').waitFor();
        await search.fill('missing'); await page.locator('[data-registry-empty="filtered"]').waitFor(); await clear();
        const tagMenu = page.locator('.tag-filter-picker');
        await tagMenu.locator('summary').click();
        await tagMenu.getByLabel('Filter by tag Registry workspace Retry').check({ force: true }); await count(2);
        await page.keyboard.press('Escape'); await clear();
        // Selection spans pages/filters; mixed state must be native indeterminate.
        await rows.first().locator('.row-select input').check();
        await page.locator('.registry-selection').waitFor();
        await poll(() => page.locator('.registry-page-select input').evaluate(el => el.indeterminate), 'Mixed selection must be indeterminate');
        await page.getByRole('checkbox', { name: 'Select all endpoints on this page' }).check();
        await poll(async () => (await page.locator('.registry-selection-summary').innerText()).includes('15 selected'), 'Page selection missing');
        await page.getByRole('button', { name: 'Next page', exact: true }).click(); await count(3);
        await poll(async () => !(await page.getByRole('checkbox', { name: 'Select all endpoints on this page' }).isChecked()), 'Page selection checkbox must reflect current page');
        await page.getByRole('checkbox', { name: 'Select all endpoints on this page' }).check();
        await poll(async () => (await page.locator('.registry-selection-summary').innerText()).includes('18 selected'), 'Cross-page selection missing');
        await search.fill('__none__');
        await poll(async () => (await page.locator('.registry-selection-summary').innerText()).includes('0 on this page'), 'Hidden selection scope missing');
        await page.locator('.registry-selection').getByRole('button', { name: 'Enable', exact: true }).click();
        await page.locator('.registry-selection').waitFor({ state: 'hidden' });
        assert.equal(saved().filter(row => row.enabled).length, 18);
        await clear();
        const first = page.locator(`[wire\\:key="endpoint-${data.ids[0]}"]`);
        await first.locator('.row-select input').check();
        await page.locator('.registry-selection').waitFor();
        assert.equal(await first.evaluate(el => el.classList.contains('is-selected')), true);
        for (const width of [1440, 1024, 740, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${theme}/${width}: horizontal overflow`);
            const controls = await page.locator('.registry-selection button:visible, .registry-selection select:visible, .registry-toolbar select:visible').evaluateAll(els => els.map(el => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width, height: r.height }; }));
            for (const r of controls) assert.ok(r.left >= 0 && r.right <= width + 1 && r.width >= 24 && r.height >= 24, `${theme}/${width}: clipped or undersized control`);
            if (width <= 440) {
                const labelsFit = await page.locator('.registry-toolbar select').evaluateAll(els => els.every(el => {
                    const style = getComputedStyle(el), canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
                    ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
                    return ctx.measureText(el.selectedOptions[0].textContent).width <= el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
                }));
                assert.ok(labelsFit, `${theme}/${width}: selected filter labels must be readable`);
            }
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.screenshot({ path: resolve(shots, `${theme}-${width}-selected.png`) });
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
        const selected = page.locator('.registry-selection');
        await selected.getByLabel('Collection for selected endpoints').selectOption(String(data.empty));
        await selected.getByRole('button', { name: 'Move to collection', exact: true }).click();
        await selected.waitFor({ state: 'hidden' });
        assert.equal(saved().find(row => row.id === data.ids[0]).collection_id, data.empty);
        await first.locator('.row-select input').check(); await selected.waitFor();
        await selected.getByText('Add tags', { exact: true }).click();
        await selected.getByLabel('Registry workspace Reviewed', { exact: true }).check();
        await selected.getByRole('button', { name: 'Apply tags' }).click(); await selected.waitFor({ state: 'hidden' });
        assert.ok(saved().find(row => row.id === data.ids[0]).tags.includes('Registry workspace Reviewed'));
        await first.locator('.row-select input').check(); await selected.waitFor();
        const downloadPromise = page.waitForEvent('download');
        await selected.getByRole('button', { name: 'Export', exact: true }).click();
        const download = await downloadPromise;
        const exported = JSON.parse(await readFile(await download.path(), 'utf8'));
        assert.equal(exported.format_version, '1.5'); assert.equal(exported.endpoints.length, 1);
        await selected.getByRole('button', { name: 'Disable', exact: true }).click(); await selected.waitFor({ state: 'hidden' });
        assert.equal(saved().find(row => row.id === data.ids[0]).enabled, false);
        // Copy uses the existing action-time builder; no secrets in HTML attributes.
        await first.getByRole('button', { name: /^Copy mock curl/ }).click();
        await poll(async () => (await page.evaluate(() => navigator.clipboard.readText())).includes('/registry-workspace/1'), 'Mock cURL not copied');
        await first.locator('.switch-control').click();
        await poll(() => saved().find(row => row.id === data.ids[0]).enabled, 'Toggle did not persist');
        await poll(async () => await first.getByLabel('Endpoint enabled', { exact: true }).isChecked() && !(await first.getByLabel('Endpoint enabled', { exact: true }).isDisabled()), 'Toggle response did not settle');
        const more = first.locator('.overflow-menu > summary');
        await more.focus(); await page.keyboard.press('Enter');
        await first.getByRole('button', { name: 'Duplicate', exact: true }).click();
        await poll(() => saved().length === 19, 'Duplicate not created');
        assert.equal(saved().find(row => row.name.startsWith('Copy of ')).enabled, false);
        await search.fill('Copy of Registry workspace'); await count(1);
        await rows.first().locator('.overflow-menu > summary').click();
        await rows.first().getByRole('button', { name: 'Delete', exact: true }).click();
        await page.locator('#confirm-dialog [data-confirm-cancel]').click();
        assert.equal(saved().length, 19, 'Cancel must preserve endpoint');
        await rows.first().getByRole('button', { name: 'Delete', exact: true }).click();
        await page.locator('#confirm-dialog [data-confirm-accept]').click();
        await page.locator('[data-registry-empty="filtered"]').waitFor(); assert.equal(saved().length, 18);
        await clear();
        await first.locator('.row-select input').check(); await selected.waitFor();
        await selected.getByRole('button', { name: 'Delete', exact: true }).click();
        await page.locator('#confirm-dialog [data-confirm-accept]').click(); await selected.waitFor({ state: 'hidden' });
        assert.equal(saved().length, 17);
        const activity = page.locator('#request-log');
        await activity.locator(':scope > summary').scrollIntoViewIfNeeded();
        await activity.locator(':scope > summary').click();
        assert.equal(await activity.evaluate(el => el.open), false);
        await activity.locator(':scope > summary').focus(); await page.keyboard.press('Enter');
        assert.equal(await activity.evaluate(el => el.open), true);
        await page.getByRole('link', { name: 'View full request log' }).click(); await page.waitForURL('**/dashboard/logs');
        await page.goto(`${base}/dashboard`);
        await rows.first().getByRole('link', { name: 'Edit', exact: true }).click(); await page.waitForURL('**/edit');
        await context.close();
        fixture('clear');
        console.log(`${theme}: filters/counts/sort/pagination, mixed/cross-page selection, all bulk and row actions, copy/export, empty states and activity pass at desktop; selected-state containment checked at 1440/1024/740/390/320px`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
