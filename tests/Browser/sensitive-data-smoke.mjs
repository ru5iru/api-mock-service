// Named, guarded, fresh disposable PostgreSQL database only. Artifacts contain FAKE canaries.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir, writeFile, appendFile } from 'node:fs/promises';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
const theme = process.env.MOCKDECK_BROWSER_THEME;
const out = process.env.AUDIT_OUTPUT;
if (!base || !out || !process.env.AUDIT_DB || process.env.MOCKDECK_BROWSER_FIXTURE !== '1' || !['light', 'dark'].includes(theme)) throw new Error('Select a guarded disposable instance, theme and external output directory.');
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/sensitive-data-browser-fixture.php'], { encoding: 'utf8', env: process.env }));
await mkdir(out, { recursive: true });
const browser = await chromium.launch({ headless: true });
const api = await request.newContext({ storageState: { cookies: [], origins: [] } });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
async function capture(location, text) {
    await writeFile(`${out}/${location}.txt`, text);
    for (const [canary, value] of Object.entries(fixture.canaries)) await appendFile(`${out}/matrix.jsonl`, `${JSON.stringify({ location, canary, found: text.includes(value) })}\n`);
}
async function dom(label, path) {
    const response = await page.goto(`${base}${path}`);
    assert.equal(response.status(), 200, path);
    await capture(label, await page.content());
}
try {
    await writeFile(`${out}/versions.json`, JSON.stringify({ chromium: browser.version(), playwright: require('playwright/package.json').version, node: process.version, theme }));
    const matched = await api.post(`${base}${fixture.url}`, { headers: fixture.headers, data: fixture.body });
    assert.equal(matched.status(), 200);
    await capture('matched-response', await matched.text());
    const unmatched = await api.post(`${base}/audit/missing?token=${fixture.canaries['QRY-TOKEN']}&ref=${fixture.canaries['QRY-PLAIN']}`, { headers: fixture.headers, data: fixture.body });
    assert.equal(unmatched.status(), 404);
    await capture('unmatched-response', await unmatched.text());
    const form = await api.post(`${base}/audit/form`, { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: `password=${fixture.canaries['BODY-FORM']}` });
    assert.equal(form.status(), 200);
    const empty = await api.get(`${base}/audit/empty?token=${fixture.canaries['QRY-TOKEN']}`);
    // The query participates in matching: force the diagnostic with the exact saved request.
    assert.equal(empty.status(), 404);
    const error = await api.get(`${base}/audit/empty`);
    assert.equal(error.status(), 500);
    await capture('diagnostic-500', await error.text());
    const expired = await api.post(`${base}/dashboard/config/exports`, { data: { redact_secrets: true } });
    assert.equal(expired.status(), 419);
    await capture('csrf-419', await expired.text());
    await dom('registry-dom', '/dashboard');
    await dom('editor-request-dom', `/dashboard/endpoints/${fixture.endpoint}/edit`);
    await page.getByRole('button', { name: 'Endpoint history', exact: true }).click();
    await page.locator('#endpoint-history-dialog[open]').waitFor();
    await capture('endpoint-history-dialog', await page.content());
    await capture('endpoint-history-panel', await page.locator('#endpoint-history-dialog').evaluate(node => node.outerHTML));
    await page.getByRole('button', { name: 'Close endpoint history', exact: true }).click();
    await page.getByRole('tab', { name: /^Response/ }).click();
    const details = page.locator('.response-row-details');
    if (!await details.evaluate(node => node.open)) await details.locator(':scope > summary').click();
    const history = details.locator('.response-history');
    if (!await history.evaluate(node => node.open)) await history.locator(':scope > summary').click();
    await history.getByRole('button', { name: 'Compare with current', exact: true }).click();
    await history.locator('.revision-diff-viewer').waitFor();
    const historyHtml = await history.evaluate(node => node.outerHTML);
    assert.ok(!historyHtml.includes(fixture.canaries.SIGN));
    await capture('response-history-panel', historyHtml);
    await page.getByRole('tab', { name: /Callback/ }).click();
    await page.getByRole('button', { name: `Configure callback for response ${fixture.response}`, exact: true }).click();
    const callback = page.locator('.callback-form');
    await callback.waitFor();
    await callback.getByRole('button', { name: 'JSON', exact: true }).click();
    await capture('editor-callback-dom', await page.content());
    const callbackHtml = await callback.evaluate(node => node.outerHTML);
    assert.ok(!callbackHtml.includes(fixture.canaries.SIGN));
    await capture('editor-callback-form', callbackHtml);
    await dom('requests-dom', '/dashboard/requests');
    let index = 0;
    for (const row of await page.locator('.log-row').all()) {
        await row.click();
        await page.locator('.log-detail-row').waitFor();
        await capture(`requests-expanded-dom-${index++}`, await page.content());
        if (process.env.MOCKDECK_EXPECT_SAFE_LOG_URL === '1') {
            const curl = await row.getByRole('button', { name: 'Copy request curl', exact: true }).getAttribute('data-copy-curl');
            assert.ok(!curl.includes(fixture.canaries['QRY-TOKEN']));
        }
    }
    await dom('callbacks-dom', '/dashboard/logs?type=callbacks');
    for (const summary of await page.locator('details.normalized-details > summary').all()) await summary.click();
    await capture('callbacks-expanded-dom', await page.content());
    await dom('config-dom', '/dashboard/config');
    await page.locator('#config-file').setInputFiles({ name: 'canary.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(fixture.document)) });
    await page.locator('.selected-file').waitFor();
    await page.locator('input[type=radio][value=upsert]').check();
    await page.getByRole('button', { name: 'Preview import', exact: true }).click();
    const apply = page.getByRole('button', { name: /^Confirm import/ });
    await apply.waitFor();
    await capture('import-preview-dom', await page.content());
    const ack = page.locator('input[wire\\:model\\.live="acknowledgeWarnings"]');
    if (await ack.count()) await ack.check();
    await apply.click();
    await page.locator('.import-success').waitFor();
    await capture('import-success-dom', await page.content());
    await capture('flash-toast-region', await page.locator('#toast-region').evaluate(node => node.outerHTML));
    await page.getByRole('button', { name: 'Undo this import', exact: true }).click();
    await page.locator('#confirm-dialog[open]').waitFor();
    await capture('import-undo-dialog', await page.content());
    await page.locator('#confirm-dialog [data-confirm-cancel]').click();
    console.log(`PASS canary browser measurements ${theme}`);
} finally {
    await api.dispose();
    await browser.close();
}
