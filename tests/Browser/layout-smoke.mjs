// Run against a local dashboard: MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/layout-smoke.mjs
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const runtimeModules = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const { chromium } = runtimeModules
    ? createRequire(`${runtimeModules}/package.json`)('playwright')
    : require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw new Error('Set MOCKDECK_BASE_URL to a running MockDeck dashboard.');
const screenshotDir = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(screenshotDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    for (const theme of ['light', 'dark']) {
        await page.goto(`${base}/dashboard`);
        await page.evaluate((value) => { localStorage.setItem('mockdeck-theme', value); }, theme);
        await page.reload();
        await page.evaluate(() => { window.__headerNode = document.querySelector('[data-persistent-topbar]'); });
        const baseline = await page.locator('[data-persistent-topbar]').boundingBox();
        assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior), 'auto');
        assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).scrollbarGutter), 'stable');
        let titleY;
        for (const [name, route] of [
            ['endpoints', ''], ['requests', 'logs'], ['callbacks', 'logs?type=callbacks'],
            ['config', 'config'], ['environments', 'environments'], ['endpoints-return', ''],
        ]) {
            // Click route links to exercise Livewire navigation and persistence.
            if (name === 'environments') {
                await page.locator('.user-menu > summary').click();
                await page.locator('.user-menu-panel a[href$="/environments"]').click();
            } else if (name === 'callbacks') {
                await page.locator('.log-type-tabs a[href*="type=callbacks"]').click();
            } else if (name === 'endpoints-return') {
                await page.locator('.topnav > a[href$="/dashboard"]').click();
            } else if (name !== 'endpoints') {
                await page.locator(`.topnav > a[href$="/dashboard/${route}"]`).click();
            }
            await page.waitForURL((url) => url.pathname === `/dashboard${route ? `/${route.split('?')[0]}` : ''}`
                && (name !== 'callbacks' || url.searchParams.get('type') === 'callbacks'));
            assert.equal(await page.evaluate(() => window.__headerNode === document.querySelector('[data-persistent-topbar]')), true, `${name}: header remounted`);
            const box = await page.locator('[data-persistent-topbar]').boundingBox();
            for (const key of ['x', 'y', 'width', 'height']) assert.ok(Math.abs(box[key] - baseline[key]) <= 2, `${name}: header ${key} drifted`);
            const y = (await page.locator('.page-shell .page-header h1').first().boundingBox()).y;
            titleY ??= y;
            assert.ok(Math.abs(y - titleY) <= 2, `${name}: title top drifted by ${y - titleY}px`);
            await page.screenshot({ path: resolve(screenshotDir, `${theme}-${name}.png`), fullPage: true });
        }
        // A short page and a forced long page must leave the persistent header in one place.
        await page.evaluate(() => { const filler = document.createElement('div'); filler.id = 'gutter-probe'; filler.style.height = '200vh'; document.querySelector('main').append(filler); });
        const withScrollbar = await page.locator('[data-persistent-topbar]').boundingBox();
        assert.ok(Math.abs(withScrollbar.x - baseline.x) <= 2 && Math.abs(withScrollbar.width - baseline.width) <= 2, 'scrollbar appearance shifted header');
        await page.locator('#gutter-probe').evaluate((node) => node.remove());
        await page.locator('a[href$="/dashboard/endpoints/create"]').first().click();
        await page.waitForURL('**/dashboard/endpoints/create');
        const editorY = (await page.locator('.page-shell .page-header h1').first().boundingBox()).y;
        assert.ok(Math.abs(editorY - titleY) <= 2, `editor: breadcrumb top drifted by ${editorY - titleY}px`);
    }
} finally {
    await browser.close();
}
