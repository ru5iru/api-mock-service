import { openEditorSection } from './editor-navigation.mjs';
// Read-only application checks. Presentation preferences are the only stored changes.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw new Error('Set MOCKDECK_BASE_URL to an accessible disposable dashboard.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'workbench');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
const errors = [];
try {
    console.log(`Browser: Chromium ${browser.version()}`);
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', error => errors.push(`${theme}: ${error.message}`));
        await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
        for (const route of ['', '/endpoints/create', '/logs', '/logs?type=callbacks', '/config', '/environments', '/docs']) {
            const response = await page.goto(`${base}/dashboard${route}`);
            assert.equal(response.status(), 200);
            await page.locator('[data-persistent-topbar]').waitFor();
            assert.equal(await page.locator('.workbench-rail').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${theme} ${route}: page overflow`);
        }
        await page.goto(`${base}/dashboard`);
        await page.evaluate(() => { window.__workbenchHeader = document.querySelector('[data-persistent-topbar]'); });
        await page.locator('.workbench-rail a[href$="/logs"]').click();
        await page.waitForURL('**/dashboard/logs');
        assert.equal(await page.evaluate(() => window.__workbenchHeader === document.querySelector('[data-persistent-topbar]')), true);
        const toggle = page.locator('[data-workbench-toggle]');
        await toggle.click();
        await page.waitForFunction(() => document.querySelector('[data-workbench-toggle]').getAttribute('aria-expanded') === 'false');
        assert.equal(await page.locator('#workbench-context').isVisible(), false);
        await page.reload();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        await toggle.click();
        await page.goto(`${base}/dashboard/endpoints/create`);
        const explorerSearch = page.getByRole('searchbox', { name: 'Find an endpoint' });
        await explorerSearch.fill('__no_such_workbench_endpoint__');
        await page.getByText('No endpoints match. Change the search or collection.').waitFor();
        await explorerSearch.fill('');
        for (const tab of ['Request', 'Matching', 'Response', 'Callback', 'Settings']) {
            await openEditorSection(page, tab);
        }
        await openEditorSection(page, 'Request');
        const curl = page.locator('#raw-curl');
        const originalCurl = await curl.inputValue();
        await curl.fill("curl 'https://example.test/workbench-unsaved'");
        await page.waitForFunction(() => window.MockDeckEditor.isDirty());
        await page.locator('.workbench-rail a[href$="/logs"]').click();
        await page.locator('#confirm-dialog').waitFor({ state: 'visible' });
        await page.locator('#confirm-dialog [data-confirm-cancel]').click();
        assert.ok(page.url().endsWith('/endpoints/create'));
        await page.locator('.endpoint-editor').waitFor();
        // Parsing may have collapsed the source while the discard dialog was open.
        if (await curl.count() === 0) await page.getByRole('button', { name: 'Edit request', exact: true }).click();
        assert.equal(await curl.inputValue(), "curl 'https://example.test/workbench-unsaved'", 'Cancelled navigation must retain the edited source');
        await curl.fill(originalCurl);
        await page.waitForFunction(() => !window.MockDeckEditor.isDirty());
        await openEditorSection(page, 'Settings');
        const priority = page.locator('#endpoint-priority');
        await priority.focus();
        await priority.scrollIntoViewIfNeeded();
        const fieldBox = await priority.boundingBox();
        const headerBox = await page.locator('[data-persistent-topbar]').boundingBox();
        const saveBox = await page.locator('[data-sticky-action-bar]').boundingBox();
        const footerBox = await page.locator('.footer').boundingBox();
        assert.ok(fieldBox.y >= headerBox.y + headerBox.height && fieldBox.y + fieldBox.height <= saveBox.y, 'Focused input must clear sticky chrome');
        assert.ok(saveBox.y + saveBox.height <= footerBox.y + 1, 'Save action bar must clear status bar');
        await priority.blur();
        const tip = page.locator('.help-tip[aria-label="Priority"]');
        await tip.hover();
        const bubble = tip.locator('[role="tooltip"]');
        await bubble.waitFor({ state: 'visible' });
        await page.locator('.page-header h1').first().hover();
        await bubble.waitFor({ state: 'hidden' });
        await tip.hover();
        await bubble.waitFor({ state: 'visible' });
        await bubble.hover();
        await page.waitForTimeout(200);
        assert.equal(await bubble.isVisible(), true, 'Pointer must be able to enter help bubble');
        await page.keyboard.press('Escape');
        await bubble.waitFor({ state: 'hidden' });
        const tipBox = await tip.boundingBox();
        assert.ok(tipBox.width >= 24 && tipBox.height >= 24);
        await page.mouse.move(0, 0);
        await page.locator('body').click({ position: { x: 10, y: 10 } });
        await page.keyboard.press('?');
        await page.locator('#shortcut-dialog').waitFor({ state: 'visible' });
        await page.locator('[data-single-key-shortcuts]').uncheck();
        await page.keyboard.press('Escape');
        await page.goto(`${base}/dashboard/logs`);
        await page.keyboard.press('n');
        assert.ok(page.url().endsWith('/dashboard/logs'));
        await page.keyboard.press('?');
        assert.equal(await page.locator('#shortcut-dialog').isVisible(), false);
        await page.reload();
        assert.equal(await page.locator('[data-single-key-shortcuts]').isChecked(), false);
        await page.locator('.footer').getByRole('button', { name: 'Shortcuts', exact: true }).click();
        await page.locator('[data-single-key-shortcuts]').check();
        await page.keyboard.press('Escape');
        await page.goto(`${base}/dashboard`);
        await page.screenshot({ path: resolve(shots, `registry-${theme}.png`), fullPage: true });
        for (const width of [1024, 390, 320]) {
            await page.setViewportSize({ width, height: 844 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${theme} ${width}: page overflow`);
            const titleBox = await page.locator('.page-header-copy').first().boundingBox();
            const actionsBox = await page.locator('.page-header-actions').first().boundingBox();
            assert.ok(titleBox.x + titleBox.width <= actionsBox.x || titleBox.y + titleBox.height <= actionsBox.y, `${theme} ${width}: title and actions overlap`);
            const control = page.locator('[data-workbench-toggle]');
            if (await control.getAttribute('aria-expanded') === 'false') await control.click();
            assert.equal(await page.locator('#workbench-context').isVisible(), true);
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('#workbench-context').isVisible(), false);
            assert.equal(await control.evaluate(node => node === document.activeElement), true);
            await page.screenshot({ path: resolve(shots, `registry-${width}-${theme}.png`) });
        }
        await page.close();
        console.log(`PASS ${theme}: routes, persistence, sidebar, workspace/pane navigation, tooltip hover/Escape, targets, shortcut opt-out, 1024/390/320 reflow`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
