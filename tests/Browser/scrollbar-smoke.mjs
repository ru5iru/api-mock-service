// Run with the application and this process sharing a dedicated migrated fixture database.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/ui-audit-fixture.php'], { env: process.env, encoding: 'utf8' }));
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'scrollbars');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const errors = [];
async function commit(page, action) {
    await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST'), action()]);
    await page.waitForTimeout(100);
}
async function fits(panel, label) {
    await panel.page().waitForTimeout(160);
    assert.equal(await panel.isVisible(), true, `${label}: panel did not open`);
    assert.ok((await panel.boundingBox()).height > 0, `${label}: panel has no height`);
    const size = await panel.evaluate(el => ({ x: el.scrollWidth - el.clientWidth, y: el.scrollHeight - el.clientHeight }));
    assert.deepEqual(size, { x: 0, y: 0 }, `${label}: unnecessary scrollbar ${JSON.stringify(size)}`);
}
async function menus(page, label) {
    const mobile = page.locator('.mobile-nav > summary');
    if (await mobile.isVisible()) await mobile.click();
    for (const selector of ['.environment-switcher', '.theme-menu', '.user-menu']) {
        const menu = page.locator(selector).filter({ visible: true }).first();
        if (!await menu.count()) continue;
        await menu.locator(':scope > summary').click();
        await fits(menu.locator(':scope > :not(summary)'), `${label}/${selector}`);
        await page.keyboard.press('Escape');
    }
    if (await mobile.isVisible()) await page.keyboard.press('Escape');
}
async function tips(page, label) {
    const tips = page.locator('.help-tip').filter({ visible: true });
    const count = await tips.count();
    for (let i = 0; i < Math.min(count, 8); i++) {
        const tip = tips.nth(i);
        await tip.scrollIntoViewIfNeeded();
        await tip.hover();
        await fits(tip.locator('.help-tip-content'), `${label}/tooltip-${i}`);
        await page.mouse.move(0, 0);
    }
}
try {
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(theme => localStorage.setItem('mockdeck-theme', theme), theme);
        for (const width of [1440, 1024, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of ['/dashboard', '/dashboard/logs', '/dashboard/logs?type=callbacks', '/dashboard/config', '/dashboard/environments', '/dashboard/docs', '/dashboard/endpoints/create']) {
                await page.goto(base + route);
                await menus(page, `${theme}/${width}/${route}`);
                await tips(page, `${theme}/${width}/${route}`);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${route}: horizontal page overflow`);
                if (route === '/dashboard') {
                    for (const selector of ['.tag-filter-picker', '.overflow-menu', '.canonical-popover']) {
                        const menu = page.locator(selector).first();
                        await menu.locator('summary').click();
                        await fits(menu.locator(':scope > :not(summary)'), `${theme}/${width}/${selector}`);
                        await page.keyboard.press('Escape');
                    }
                }
            }
            await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
            for (const name of ['Request', 'Matching', 'Response', 'Callback']) {
                await commit(page, () => page.getByRole('tab', { name: new RegExp('^' + name) }).click());
                await tips(page, `${theme}/${width}/${name}`);
                assert.equal(await page.locator('.endpoint-panel-viewport .sticky-card').first().evaluate(el => getComputedStyle(el).overflowY), 'visible');
                assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'clip');
            }
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit?tab=response`);
        await commit(page, () => page.locator('.selection-response-card').first().getByRole('button', { name: 'Edit', exact: true }).click());
        const form = page.locator('.response-form');
        await commit(page, () => form.getByRole('button', { name: 'Template', exact: true }).click());
        await commit(page, () => form.getByRole('button', { name: 'Builder', exact: true }).click());
        await commit(page, () => form.getByRole('button', { name: 'Add field', exact: false }).last().click());
        await commit(page, () => form.locator('.schema-type select').first().selectOption('faker'));
        const picker = form.locator('.faker-picker').first();
        for (const height of [900, 600, 900]) {
            await page.setViewportSize({ width: 1024, height });
            await picker.locator('summary').click();
            await fits(picker.locator('.faker-picker-panel'), `${theme}/${height}/Faker shell`);
            const list = picker.locator('.faker-options');
            assert.ok(await list.evaluate(el => el.scrollHeight > el.clientHeight), 'Long Faker list must remain scrollable');
            const before = await picker.locator('.faker-picker-panel').boundingBox();
            await list.evaluate(el => { el.scrollTop = 200; });
            await page.waitForTimeout(100);
            const after = await picker.locator('.faker-picker-panel').boundingBox();
            assert.ok(Math.abs(before.height - after.height) < 1, 'Scrolling shrank Faker picker');
            await page.keyboard.press('Escape');
        }
        // Deliberately long menus still scroll; a refresh must recover after resizing.
        await page.goto(base + '/dashboard');
        const themeMenu = page.locator('.theme-menu').filter({ visible: true }).first();
        await themeMenu.locator('summary').click();
        const panel = themeMenu.locator('.theme-menu-panel');
        await panel.evaluate(el => { for (let n = 0; n < 20; n++) el.append(el.querySelector('button').cloneNode(true)); });
        await page.waitForTimeout(150);
        assert.ok(await panel.evaluate(el => el.scrollHeight > el.clientHeight), 'Long menu lost necessary scrolling');
        await page.keyboard.press('Escape');
        await page.reload();
        for (const selector of ['.environment-switcher', '.theme-menu', '.user-menu']) {
            const menu = page.locator(selector).filter({ visible: true }).first();
            await menu.locator('summary').click();
            await fits(menu.locator(':scope > :not(summary)'), selector);
            await page.screenshot({ path: resolve(shots, `${theme}-${selector.slice(1)}.png`) });
            await page.keyboard.press('Escape');
        }
        await page.locator('.endpoint-signature-version .help-tip').first().hover();
        await fits(page.locator('.endpoint-signature-version .help-tip-content').first(), `${theme}/signature capture`);
        await page.screenshot({ path: resolve(shots, `${theme}-signature.png`) });
        for (const [name, html] of Object.entries(fixture.shells)) {
            await page.route(`${base}/__scrollbar-audit/${name}`, route => route.fulfill({ contentType: 'text/html', body: html.replaceAll('http://localhost', base) }));
            await page.goto(`${base}/__scrollbar-audit/${name}`);
            await menus(page, `${theme}/${name}`);
        }
        await page.close();
        console.log(`${theme}: dashboard routes, four editor tabs, standalone shells, fitting menus/tooltips, and single-scroll Faker picker passed`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
