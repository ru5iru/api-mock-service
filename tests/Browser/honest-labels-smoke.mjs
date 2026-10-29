import { openEditorSection } from './editor-navigation.mjs';
// Fresh disposable PostgreSQL database per theme; fixture PHP must target the same instance.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
const theme = process.env.MOCKDECK_BROWSER_THEME;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1' || !['light', 'dark'].includes(theme)) throw new Error('Use a fresh disposable database and choose light or dark.');
const php = process.env.MOCKDECK_PHP ?? 'php';
const seed = () => JSON.parse(execFileSync(php, ['tests/Support/honest-labels-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
const inspect = () => JSON.parse(execFileSync(php, ['tests/Support/honest-labels-browser-fixture.php', 'inspect', String(fixture.environment_id)], { env: process.env, encoding: 'utf8' }));
async function poll(check, message) {
    const end = Date.now() + 12000;
    while (Date.now() < end) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 60)); }
    throw new Error(message);
}
const fixture = seed();
const initial = inspect();
const browser = await chromium.launch({ headless: true });
const client = await request.newContext({ storageState: { cookies: [], origins: [] } });
const shots = process.env.MOCKDECK_SCREENSHOT_DIR;
if (shots) await mkdir(shots, { recursive: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
    const fits = async label => {
        for (const width of [390, 740, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${label}/${width}: horizontal overflow`);
            if (shots) await page.screenshot({ path: `${shots}/${label}-${width}.png`, fullPage: true });
        }
    };
    await page.goto(`${base}/dashboard/config`);
    await page.locator('#config-file').setInputFiles({ name: 'q02-mixed.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(fixture.document)) });
    await page.locator('.selected-file').waitFor();
    await page.locator('input[type=radio][value=upsert]').check();
    await page.locator('input[wire\\:model\\.live="replaceResponses"]').check();
    await page.getByRole('button', { name: 'Preview import', exact: true }).click();
    const apply = page.getByRole('button', { name: /^Confirm import/ }); await apply.waitFor();
    const ack = page.locator('input[wire\\:model\\.live="acknowledgeWarnings"]'); if (await ack.count()) await ack.check();
    await apply.click();
    const success = page.locator('.import-success'); await success.waitFor();
    const text = await success.innerText();
    const restoredResponseCount = initial.responses.filter(row => row.mock_endpoint_id === fixture.endpoint).length;
    assert.ok(text.includes(`Restore 1 endpoint and ${restoredResponseCount} responses`));
    for (const phrase of ['Restores', 'Retains', 'Q02 before import', 'Q02 retained new endpoint', 'including responses removed by authoritative replacement', 'separately created Environment variables are retained', 'Runtime counters are untouched.']) assert.ok(text.includes(phrase), `Missing success text: ${phrase}`);
    const applied = inspect();
    assert.equal(applied.endpoints.find(row => row.id === fixture.endpoint).name, 'Q02 after import');
    assert.equal(applied.responses.some(row => row.id === fixture.removed), false);
    await fits('import-success');
    const undo = page.getByRole('button', { name: 'Undo this import', exact: true });
    await undo.click();
    const dialog = page.locator('#confirm-dialog'); await poll(() => dialog.evaluate(node => node.open), 'Undo confirmation did not open');
    assert.equal(await dialog.locator('[data-confirm-cancel]').evaluate(node => node === document.activeElement), true);
    assert.equal(await dialog.locator('[data-confirm-accept]').evaluate(node => node.classList.contains('button-danger')), true);
    const message = await dialog.locator('[data-confirm-message]').innerText();
    for (const phrase of ['Q02 before import', 'Q02 retained new endpoint', 'including responses removed by authoritative replacement', 'separately created Environment variables are retained', 'Runtime counters are untouched.']) assert.ok(message.includes(phrase), `Missing confirmation text: ${phrase}`);
    await fits('undo-confirmation');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    assert.deepEqual(inspect(), applied, 'Cancelling undo changed persisted data');
    await undo.click(); await dialog.locator('[data-confirm-accept]').click();
    await poll(async () => (await success.innerText()).includes('Import undone'), 'Undo did not finish');
    const restored = inspect();
    assert.equal(restored.endpoints.find(row => row.id === fixture.endpoint).name, 'Q02 before import');
    assert.ok(restored.endpoints.some(row => row.uuid === fixture.created_uuid));
    assert.equal(restored.responses.find(row => row.id === fixture.response).body, 'before import');
    assert.equal(restored.responses.find(row => row.id === fixture.removed).body, 'restore removed response');
    assert.deepEqual(restored.states, initial.states, 'Undo changed runtime state');
    assert.equal(restored.variable, initial.variable, 'Undo changed a separately created variable');
    const unmatched = await client.get(`${base}/q02/existing`, { headers: { Cookie: 'q02=browser' } });
    assert.equal(unmatched.status(), 404);
    const requestId = unmatched.headers()['x-request-id'];
    await page.goto(`${base}/dashboard/logs`);
    await page.locator('.log-row').filter({ has: page.locator(`code[title*="${requestId}"]`) }).click();
    const detail = page.locator('.log-detail-row'); await detail.waitFor();
    for (const phrase of ['Recorded request event', 'Current configuration', 'Nearest current configured candidates', 'Q02 before import', 'The reconstructed cURL contains method and URL only; add headers and body before using it.']) assert.ok((await detail.innerText()).includes(phrase));
    await fits('recorded-and-current');
    await page.goto(`${base}/dashboard/endpoints/${fixture.editor}/edit?tab=response`);
    await openEditorSection(page, 'Response');
    const outer = page.locator(`[wire\\:key="response-${fixture.rule}"] > details`);
    if (!await outer.evaluate(node => node.open)) await outer.locator(':scope > summary').click();
    const tip = outer.getByRole('button', { name: 'Match before response selection', exact: true });
    await tip.waitFor();
    const addRule = outer.getByRole('button', { name: 'Add condition', exact: true });
    const beforeRules = await outer.locator('.rule-condition-row').count();
    await addRule.click();
    await poll(async () => await outer.locator('.rule-condition-row').count() === beforeRules + 1, 'Livewire rule update did not finish');
    for (const width of [390, 740, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        await addRule.focus(); await tip.focus();
        const bubble = tip.getByRole('tooltip');
        await poll(async () => {
            const box = await bubble.boundingBox();
            return await bubble.isVisible() && box && box.x >= 0 && box.x + box.width <= width + 1
                && box.y >= 0 && box.y + box.height <= 1001;
        }, `Rule help escaped ${width}px after Livewire update`);
        assert.match(await bubble.innerText(), /A rule cannot make a different request match/);
        assert.match(await bubble.innerText(), /exclude it in Matching first/);
        assert.ok(await tip.getAttribute('aria-describedby'));
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        if (shots) await page.screenshot({ path: `${shots}/rule-help-${width}.png`, fullPage: true });
    }
    await page.goto(`${base}/dashboard/endpoints/${fixture.editor}/edit?tab=callback`);
    await page.getByRole('tab', { name: /^Callback/ }).click();
    await page.getByRole('button', { name: `Configure callback for response ${fixture.rule}`, exact: true }).click();
    await page.locator('#callback-url').waitFor();
    assert.match(await page.locator('.callback-form').innerText(), /current saved callback settings; retries reuse those resolved values/);
    assert.match(await page.locator('.callback-form').innerText(), /does not cancel a delivery already queued/);
    await fits('callback-timing');
    assert.deepEqual(errors, []);
    console.log(`${theme}: Chromium ${browser.version()}; mixed import, Cancel/confirm, independent restoration/retention/runtime readback, real Logs groups, and post-Livewire rule help passed at 390/740/1440px`);
} finally { await client.dispose(); await browser.close(); }
