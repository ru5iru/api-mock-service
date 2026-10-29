import { openEditorSection } from './editor-navigation.mjs';
// One fresh guarded PostgreSQL database per theme, never an operator instance.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
const theme = process.env.MOCKDECK_BROWSER_THEME;
if (!base || !['light', 'dark'].includes(theme) || process.env.MOCKDECK_BROWSER_FIXTURE !== '1' || !process.env.AUDIT_DB) throw Error('Use a fresh guarded PostgreSQL database and explicit theme.');
const fixture = (action, label, id) => JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/global-save-browser-fixture.php', action, label, ...(id ? [String(id)] : [])], { env: process.env, encoding: 'utf8' }));
async function poll(check, message) {
    const end = Date.now() + 16000;
    while (Date.now() < end) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 70)); }
    throw Error(message);
}
const browser = await chromium.launch({ headless: true });
console.log(`${theme}: Chromium ${browser.version()}, Playwright ${require('playwright/package.json').version}, Node ${process.version}`);
const shots = process.env.MOCKDECK_SCREENSHOT_DIR;
if (shots) await mkdir(shots, { recursive: true });
try {
    for (const width of [390, 740, 1440]) for (const scenario of ['preflight', 'race', 'validation']) {
        const label = `${theme}-${width}-${scenario}`;
        const f = fixture('create', label);
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.goto(`${base}/dashboard/endpoints/${f.endpoint}/edit`);
        await page.getByRole('button', { name: 'Edit request', exact: true }).click();
        await page.locator('#raw-curl').fill(f.curl);
        const save = page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true });
        await poll(() => save.isEnabled(), 'Request preview did not settle');
        await openEditorSection(page, 'Response');
        const edit = async id => {
            await page.locator(`[wire\\:key="response-${id}"]`).getByRole('button', { name: 'Edit', exact: true }).click();
            await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${id}`), 'Response did not open');
        };
        await edit(f.first); await page.locator('#response-body').fill('after-a');
        await edit(f.second); await page.locator('#response-body').fill('after-b');
        let competing;
        if (scenario === 'preflight') competing = fixture('duplicate', label);
        if (scenario === 'validation') await page.locator('#response-weight').fill('0');
        if (scenario === 'race') {
            await page.route('**/*', async route => {
                const request = route.request();
                if (!competing && request.method() === 'POST' && request.url().includes('/update')) {
                    const data = request.postDataJSON();
                    if (data.components?.some(component => component.calls?.some(call => call.method === '__dispatch' && call.params?.[0] === 'save-response-drafts'))) competing = fixture('duplicate', label);
                }
                await route.continue();
            });
        }
        const before = fixture('snapshot', label, f.endpoint);
        await save.click();
        const expected = scenario === 'preflight' ? 'Changes were not saved. Correct the highlighted request settings and try again.'
            : scenario === 'race' ? 'Some changes were saved. Request settings were not saved. Correct the highlighted fields and try again.'
                : 'Response changes were not saved. Correct the highlighted fields and try again.';
        const summary = page.locator('[data-save-result]');
        await poll(async () => (await summary.innerText()).includes(expected), 'Approved save summary missing');
        const after = fixture('snapshot', label, f.endpoint);
        assert.deepEqual(after.endpoint, before.endpoint, 'Rejected endpoint changed');
        assert.equal(after.responses.find(row => row.id === f.first).body, scenario === 'race' ? 'after-a' : 'before-a');
        assert.equal(after.responses.find(row => row.id === f.second).body, scenario === 'race' ? 'after-b' : 'before-b');
        assert.equal(after.revisions.length, scenario === 'race' ? 2 : 0, 'Unexpected committed revision count');
        const state = async key => page.locator(`[data-save-scope="${key}"] dd`).innerText();
        assert.equal(await state(`response:${f.first}`), scenario === 'race' ? 'Saved' : 'Not saved');
        assert.equal(await state(`response:${f.second}`), scenario === 'validation' ? 'Needs attention' : scenario === 'race' ? 'Saved' : 'Not saved');
        assert.equal(await page.locator('[data-endpoint-tabs]').getByRole('tab', { name: /^Editor/ }).getAttribute('aria-selected'), 'true');
        await poll(() => page.evaluate(id => document.activeElement?.id === id, scenario === 'validation' ? 'response-weight' : 'raw-curl'), 'Focus did not reach the first invalid field');
        assert.equal(await page.evaluate(() => window.MockDeckEditor.isDirty()), true);
        assert.equal(await page.locator('.response-manager').getAttribute('data-server-dirty'), scenario === 'race' ? 'false' : 'true');
        assert.equal(await page.locator('[data-toast]').filter({ hasText: /Response (added|updated)\./ }).count(), 0, 'Stray response success toast');
        await summary.scrollIntoViewIfNeeded();
        assert.equal(await summary.isVisible(), true);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Horizontal overflow');
        assert.equal(await save.getAttribute('aria-label') ?? (await save.innerText()).trim(), 'Save changes');
        assert.ok(await page.locator('[data-sticky-action-bar]').evaluate(el => el.getBoundingClientRect().bottom <= innerHeight + 1));
        if (shots) await page.screenshot({ path: `${shots}/${label}.png`, fullPage: true });
        if (scenario === 'race') {
            await openEditorSection(page, 'Response');
            await edit(f.first);
            await page.locator('#response-body').fill('a later draft');
            const oldState = page.locator(`[data-save-scope="response:${f.first}"]`);
            await poll(async () => !(await oldState.isVisible()), 'Later edit retained stale Saved state');
            await openEditorSection(page, 'Request');
            await openEditorSection(page, 'Response');
            await poll(async () => !(await oldState.isVisible()), 'Parent morph resurrected stale Saved state');
            await page.locator('#response-body').fill('after-a');
            await openEditorSection(page, 'Request');
        }
        // Correct the failing scope, then retry. Old Saved/Not saved labels must disappear on edit.
        if (scenario !== 'validation') {
            await page.locator('#raw-curl').fill(f.curl.replace('/new', '/corrected'));
            await poll(() => save.isEnabled(), 'Save stayed disabled after correcting request');
            await openEditorSection(page, 'Response');
        } else {
            await page.locator('#response-weight').fill('1');
            await poll(() => save.isEnabled(), 'Save stayed disabled after child failure');
        }
        await save.click();
        await poll(async () => (await page.locator('.response-form h3').innerText()) === 'Add response', 'Retry did not complete');
        await poll(async () => (await page.locator('.endpoint-editor').getAttribute('data-save-pending')) === 'false', 'Save stayed pending');
        const saved = fixture('snapshot', label, f.endpoint);
        assert.equal(saved.responses.find(row => row.id === f.first).body, 'after-a');
        assert.equal(saved.responses.find(row => row.id === f.second).body, 'after-b');
        assert.equal(saved.responses.length, 2, 'Retry duplicated a response');
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`${label}: PASS summary, states, database/revisions, dirty scopes, retry, no stray toast, names, sticky bar and reflow`);
    }
    // Deliver R to the server, then deliberately lose its acknowledgement.
    const label = `${theme}-lost-new-response`;
    const f = fixture('create', label);
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
    await page.goto(`${base}/dashboard/endpoints/${f.endpoint}/edit?tab=response`);
    await page.locator('#response-body').fill('new response before lost acknowledgement');
    let childSaves = 0;
    let aborted = false;
    await page.route('**/*', async route => {
        const r = route.request();
        if (r.method() === 'POST' && r.url().includes('/update') && r.postDataJSON().components?.some(c => c.calls?.some(call => call.method === '__dispatch' && call.params?.[0] === 'save-response-drafts'))) {
            childSaves++;
            if (!aborted) {
                aborted = true;
                assert.equal(await page.locator('#response-body').isDisabled(), true, 'Editable draft was not held during save');
                await route.fetch();
                await route.abort('failed');
                return;
            }
        }
        await route.continue();
    });
    await page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).click();
    await poll(async () => (await page.locator('[data-save-result]').innerText()).includes('Save result could not be confirmed. Check the saved configuration before trying again.'), 'Lost acknowledgement was falsely reported');
    await poll(async () => !(await page.locator('#response-body').isDisabled()), 'Controls stayed held after lost acknowledgement');
    assert.equal(await page.evaluate(() => window.MockDeckEditor.isDirty()), true);
    assert.equal(fixture('snapshot', label, f.endpoint).responses.length, 3, 'Server did not commit the deliberately unacknowledged new response');
    assert.equal(childSaves, 1, 'Client automatically replayed a mutation');
    await page.close();
    console.log(`${label}: PASS committed-but-unacknowledged save, dirty protection, released controls, no automatic mutation replay`);
} finally { await browser.close(); }
