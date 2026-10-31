import { openEditorSection } from './editor-navigation.mjs';
// Dedicated fixture database only. Exercise the endpoint's true-tab contract in both themes.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database and set MOCKDECK_BASE_URL.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const errors = [];
async function poll(check, message) {
    const end = Date.now() + 10000;
    while (Date.now() < end) { if (await check()) return; await new Promise(r => setTimeout(r, 60)); }
    throw new Error(message);
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/wave1-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 1001, height: 600 } });
        page.on('pageerror', error => errors.push(`${theme}: ${error.message}`));
        page.on('dialog', dialog => { errors.push(`Native ${dialog.type()} dialog`); dialog.dismiss(); });
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        const commit = async action => {
            const [response] = await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components.some(c => c.calls.some(call => !['touchSection', '__dispatch'].includes(call.method)))), action()]);
            await response.finished();
            await page.waitForTimeout(100);
        };
        const tab = async name => {
            const scroll = await page.evaluate(() => scrollY);
            await openEditorSection(page, name);
            const workspace = ['Request', 'Matching', 'Response'].includes(name) ? 'Editor' : name;
            await poll(async () => (await page.locator('[data-endpoint-tabs]').getByRole('tab', { name: new RegExp('^' + workspace) }).getAttribute('aria-selected')) === 'true', 'Workspace did not activate');
            assert.equal(await page.evaluate(() => scrollY), scroll, 'Task switching scrolled the document');
            assert.equal(await page.locator('#panel-editor:visible, #panel-settings:visible, #panel-callback:visible').count(), 1, 'Exactly one primary task panel must be visible');
            assert.equal(await page.locator('#panel-response').isVisible(), workspace === 'Editor');
        };
        const title = page.getByRole('button', { name: 'Edit endpoint name', exact: true });
        await title.click();
        await page.getByRole('textbox', { name: 'Endpoint name', exact: true }).fill('Cancelled name');
        await page.keyboard.press('Escape');
        assert.match(await title.innerText(), /Wave 1 browser fixture/);
        await title.click();
        await page.getByRole('textbox', { name: 'Endpoint name', exact: true }).fill('Inline title');
        await commit(() => page.keyboard.press('Enter'));
        await page.reload();
        assert.match(await title.innerText(), /^Inline title/);
        await title.click();
        await page.getByRole('textbox', { name: 'Endpoint name', exact: true }).fill('');
        await commit(() => page.locator('#tab-settings').click());
        await poll(async () => (await title.innerText()).includes('GET /wave1-browser'), 'Cleared title did not restore the derived name');
        await page.reload();
        assert.match(await title.innerText(), /GET \/wave1-browser/);
        assert.equal(await page.locator('#endpoint-name').count(), 0, 'Standalone Name field remains');
        await tab('Settings');
        await page.locator('#endpoint-priority').fill('77');
        await tab('Response');
        await commit(() => page.locator('.selection-response-card').first().getByRole('button', { name: 'Edit', exact: true }).click());
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.first}`), 'Response edit did not finish');
        await page.locator('#response-body').fill('{"draft":"kept"}');
        await tab('Callback');
        await commit(() => page.locator('.callback-response-row').first().getByRole('button').click());
        await page.locator('#callback-url').fill('https://receiver.test/tab-draft');
        await tab('Matching');
        await tab('Settings');
        assert.equal(await page.locator('#endpoint-priority').inputValue(), '77');
        await tab('Response');
        assert.equal(await page.locator('#response-body').inputValue(), '{"draft":"kept"}');
        assert.equal(await page.locator('#panel-response #callback-url').count(), 0);
        await tab('Callback');
        assert.equal(await page.locator('#callback-url').inputValue(), 'https://receiver.test/tab-draft');
        for (const width of [999, 1000, 1001, 1440, 390]) {
            await page.setViewportSize({ width, height: 600 });
            for (const name of ['Request', 'Matching', 'Response', 'Callback']) {
                await tab(name);
                const pane = page.locator('[data-editor-viewport]');
                await pane.evaluate(el => el.scrollTop = el.scrollHeight / 2);
                await poll(async () => {
                    const bar = await page.locator('#save-actions').boundingBox();
                    const stage = await pane.boundingBox();
                    const statusHeight = parseFloat(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--shell-status-height')));
                    const headerActions = await page.locator('#save-actions').evaluate(el => getComputedStyle(el).getPropertyValue('--actionbar-placement').trim() === 'header');
                    return headerActions ? bar.y >= 46 && bar.y + bar.height <= stage.y && stage.y + stage.height <= 600 - statusHeight + 1 : Math.abs(bar.y + bar.height + statusHeight - 600) < 1 && stage.y + stage.height <= bar.y - 15;
                }, `${theme}/${width}/${name}: action bar obstructs scrollable content`);
                assert.equal(await page.locator('#save-actions').evaluate(el => el.parentElement.tagName), 'BODY');
                const signature = page.locator('.preview-column');
                assert.equal(await signature.isVisible(), ['Request', 'Matching', 'Response'].includes(name));
                assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'clip');
                assert.equal(await page.evaluate(() => scrollY), 0, 'Outer page scrolled beside the tab viewport');
                if (await signature.isVisible()) {
                    assert.equal(await signature.locator('.sticky-card').evaluate(el => getComputedStyle(el).overflowY), 'visible');
                    await poll(async () => {
                        const owner = width > 650 ? page.locator('.endpoint-request-workspace') : pane;
                        await owner.evaluate(el => el.scrollTop = el.scrollHeight);
                        const card = await signature.boundingBox();
                        const bounds = await pane.boundingBox();
                        return card.y + card.height <= bounds.y + bounds.height + 1;
                    }, `${theme}/${width}/${name}: Signature end is unreachable`);
                    await pane.evaluate(el => el.scrollTop = 0);
                }
                assert.equal(await page.locator('.endpoint-invocation-actions').isVisible(), true, 'Saved invocation actions must stay reachable');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${theme}/${width}/${name}: horizontal overflow`);
                await page.getByRole('button', { name: 'Endpoint history', exact: true }).click();
                assert.equal(await page.locator('#endpoint-history-dialog').evaluate(el => el.open), true);
                await page.keyboard.press('Escape');
                assert.equal(await page.getByRole('button', { name: 'Endpoint history', exact: true }).evaluate(el => el === document.activeElement), true);
                if (width === 1001) await page.screenshot({ path: resolve(shots, `tabs-${theme}-${name.toLowerCase()}.png`) });
            }
        }
        for (const width of [390, 1000]) {
            await page.setViewportSize({ width, height: 450 });
            for (const name of ['Request', 'Matching', 'Response', 'Callback']) {
                await tab(name);
                await poll(async () => {
                    const bar = await page.locator('#save-actions').boundingBox();
                    const pane = await page.locator('[data-editor-viewport]').boundingBox();
                    const statusHeight = parseFloat(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--shell-status-height')));
                    const headerAction = await page.locator('#save-actions').evaluate(el => getComputedStyle(el).getPropertyValue('--actionbar-placement').trim() === 'header');
                    return headerAction
                        ? bar.y >= 46 && bar.y + bar.height <= pane.y && pane.y + pane.height <= 450 - statusHeight + 1
                        : Math.abs(bar.y + bar.height + statusHeight - 450) < 1 && pane.y + pane.height <= bar.y - 15;
                }, `${theme}/${width}/450/${name}: short viewport content is obstructed`);
            }
        }
        await page.setViewportSize({ width: 1001, height: 600 });
        await tab('Matching');
        const scrollContract = await page.evaluate(() => {
            const pane = document.querySelector('[data-editor-viewport]');
            const card = pane.querySelector('.sticky-card');
            return {
                rootOverflow: getComputedStyle(document.documentElement).overflowY,
                bodyOverflow: getComputedStyle(document.body).overflowY,
                paneOverflow: getComputedStyle(pane).overflowY,
                requestOverflow: getComputedStyle(pane.querySelector('.endpoint-request-workspace')).overflowY,
                cardOverflow: getComputedStyle(card).overflowY,
                cardPosition: getComputedStyle(card).position,
            };
        });
        assert.deepEqual(scrollContract, { rootOverflow: 'clip', bodyOverflow: 'clip', paneOverflow: 'hidden', requestOverflow: 'auto', cardOverflow: 'visible', cardPosition: 'static' });
        await page.locator('.endpoint-request-workspace').evaluate(el => el.scrollTop = el.scrollHeight);
        assert.equal(await page.evaluate(() => scrollY), 0, 'Endpoint content scrolled the outer document');
        await page.locator('.endpoint-request-workspace').evaluate(el => el.scrollTop = 0);
        await tab('Response');
        const row = page.locator('.selection-response-card').last();
        const remove = row.getByRole('button', { name: 'Delete', exact: true });
        if (await row.locator('.overflow-menu > summary').count()) await row.locator('.overflow-menu > summary').click();
        await remove.click();
        assert.equal(await page.locator('#confirm-dialog').evaluate(el => el.open), true);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.selection-response-card').count(), 2, 'Cancel deleted the response');
        assert.equal(await remove.evaluate(el => el === document.activeElement), true);
        await remove.click();
        await commit(() => page.locator('#confirm-dialog [data-confirm-accept]').click());
        await poll(async () => await page.locator('.selection-response-card').count() === 1, 'Confirm did not delete the response');
        const editorId = await page.locator('.endpoint-editor').getAttribute('wire:id');
        await page.locator('#save-actions button[type=submit]').click();
        await poll(async () => (await page.locator('.endpoint-editor').getAttribute('wire:id')) !== editorId, 'Global save did not complete its navigation');
        await page.reload();
        await tab('Settings');
        assert.equal(await page.locator('#endpoint-priority').inputValue(), '77', 'Global save lost Request draft');
        await tab('Response');
        await commit(() => page.locator('.selection-response-card').first().getByRole('button', { name: 'Edit', exact: true }).click());
        assert.equal(await page.locator('#response-body').inputValue(), '{"draft":"kept"}', 'Global save lost response draft');
        await tab('Callback');
        assert.equal(await page.locator('#callback-url').inputValue(), 'https://receiver.test/tab-draft', 'Global save lost callback draft');
        await page.goto(`${base}/dashboard/endpoints/create`);
        await tab('Callback');
        assert.match(await page.locator('#panel-callback').innerText(), /Add a response first|Create the endpoint first/);
        await page.goto(`${base}/dashboard/endpoints`);
        assert.notEqual(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'clip', 'Editor scroll containment leaked onto the endpoint list');
        await page.close();
        console.log(`${theme}: inline title, workspace tasks/panes, retained drafts/global save, history, confirm/cancel, all-tab action bounds at 999/1000/1001/1440/390px pass`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
