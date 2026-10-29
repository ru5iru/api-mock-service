import { openEditorSection } from './editor-navigation.mjs';
// Run each theme against its own fresh disposable database.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
const theme = process.env.MOCKDECK_BROWSER_THEME;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1' || !['light', 'dark'].includes(theme)) throw new Error('Use a fresh disposable database and set MOCKDECK_BROWSER_THEME=light or dark.');
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/first-run-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
const client = await request.newContext({ storageState: { cookies: [], origins: [] } });
async function poll(check, message) {
    const end = Date.now() + 12000;
    while (Date.now() < end) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 60)); }
    throw new Error(message);
}
const widths = [390, 740, 1440];
const screenshots = process.env.MOCKDECK_SCREENSHOT_DIR;
if (screenshots) await mkdir(screenshots, { recursive: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
    console.log(`${theme}: Chromium ${browser.version()}`);
    const fits = async label => {
        for (const width of widths) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${label}/${width}: horizontal overflow`);
            if (label === 'matching') {
                await page.getByRole('checkbox', { name: /^Ignore cookies/ }).focus();
                await page.getByRole('button', { name: 'Browser cookies and matching', exact: true }).focus();
                await poll(async () => {
                    const box = await page.getByRole('button', { name: 'Browser cookies and matching', exact: true }).getByRole('tooltip').boundingBox();
                    return box && box.x >= 0 && box.x + box.width <= width + 1;
                }, `Cookie tooltip escaped the ${width}px viewport`);
            }
            if (screenshots && ['registry empty', 'response empty', 'matching'].includes(label)) {
                await page.screenshot({ path: `${screenshots}/${label.replaceAll(' ', '-')}-${width}.png`, fullPage: true });
            }
        }
    };
    await page.goto(`${base}/dashboard/endpoints/create`);
    await page.locator('#raw-curl').fill(`curl 'https://example.test/q03/first-${theme}'`);
    const save = page.locator('#save-actions button[type=submit]');
    await poll(() => save.isEnabled(), 'Request was not ready to create');
    await save.click();
    await page.waitForURL(/\/endpoints\/\d+\/edit\?tab=response/);
    const endpoint = Number(page.url().match(/\/endpoints\/(\d+)\/edit/)[1]);
    const empty = await client.get(`${base}/q03/first-${theme}`);
    assert.equal(empty.status(), 500);
    assert.deepEqual(await empty.json(), { error: 'The matched mock has no configured responses', endpoint_id: endpoint });
    assert.equal((await client.get(`${base}/q03/unknown`)).status(), 404);
    await page.goto(`${base}/dashboard`);
    const row = page.locator(`[wire\\:key="endpoint-${endpoint}"]`);
    assert.equal(await row.locator('.ui-badge').filter({ hasText: /^No response$/ }).count(), 1);
    const add = row.getByRole('link', { name: 'Add response', exact: true });
    assert.ok((await add.getAttribute('href')).endsWith('?tab=response'));
    await fits('registry empty');
    await add.focus(); await add.press('Enter');
    await page.waitForURL(/\?tab=response$/);
    const cue = page.locator('#response-empty-note');
    await cue.waitFor();
    assert.equal(await cue.innerText(), 'No response yet. Add one so this endpoint can answer requests. Until then it returns a diagnostic 500.');
    assert.equal(await page.locator('#response-pane-heading').getAttribute('aria-describedby'), 'response-empty-note');
    await fits('response empty');
    await openEditorSection(page, 'Matching');
    assert.equal(await page.getByRole('checkbox', { name: /^Ignore cookies/ }).isChecked(), false);
    const tip = page.getByRole('button', { name: 'Browser cookies and matching', exact: true });
    await tip.focus();
    await poll(() => tip.getByRole('tooltip').isVisible(), 'Cookie tip did not open on focus');
    assert.match(await tip.getByRole('tooltip').innerText(), /Cookie header does not match an endpoint saved without one/);
    await fits('matching');
    await page.keyboard.press('Escape');
    await openEditorSection(page, 'Response');
    await page.evaluate(() => { window.q03DocumentMarker = 'same document'; });
    await page.locator('#response-body').fill('{"ok":true}');
    await page.locator('.response-form').getByRole('button', { name: 'Add response', exact: true }).click();
    await poll(async () => await cue.count() === 0 && await page.locator('#response-pane-heading').getAttribute('aria-describedby') === null, 'Saved response did not clear child and parent cues');
    assert.equal(await page.evaluate(() => window.q03DocumentMarker), 'same document');
    const success = await client.get(`${base}/q03/first-${theme}`);
    assert.equal(success.status(), 200); assert.deepEqual(await success.json(), { ok: true });
    await page.goto(`${base}/dashboard`);
    assert.equal(await row.getByRole('link', { name: 'Add response', exact: true }).count(), 0);
    assert.equal(await row.locator('.ui-badge').filter({ hasText: /^No response$/ }).count(), 0);
    const imported = page.locator(`[wire\\:key="endpoint-${fixture.imported}"]`);
    await imported.getByRole('link', { name: 'Add response', exact: true }).click();
    await cue.waitFor(); await fits('imported response empty');
    await page.locator('.response-form').getByRole('button', { name: 'Add response', exact: true }).click();
    await poll(async () => await cue.count() === 0, 'Imported response cue persisted after save');
    await page.goto(`${base}/dashboard`);
    assert.equal(await imported.getByRole('link', { name: 'Add response', exact: true }).count(), 0);
    for (const [method, path, expected] of [['GET', '/q03/cookies', true], ['POST', '/q03/cookies', false], ['GET', '/q03/another-path', false]]) {
        const response = await client.fetch(`${base}${path}`, { method, headers: { Cookie: 'browser=1' } });
        assert.equal(response.status(), 404);
        const id = response.headers()['x-request-id'];
        await page.goto(`${base}/dashboard/logs`);
        const logRow = page.locator('.log-row').filter({ has: page.locator(`code[title*="${id}"]`) });
        await logRow.click();
        await page.locator('.activity-inspector').waitFor();
        assert.equal(await page.locator('[data-cookie-matching-hint]').count(), expected ? 1 : 0);
        if (expected) assert.match(await page.locator('[data-cookie-matching-hint]').innerText(), /^If this request came from a browser/);
        await fits(`logs ${method} ${path}`);
    }
    assert.deepEqual(errors, []);
    console.log(`${theme}: create/import zero responses, reactive cues, unchanged 500/404/200, cookie tip and conditional Logs hint passed at ${widths.join('/')}`);
} finally { await client.dispose(); await browser.close(); }
