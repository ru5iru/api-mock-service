// Confirm and cancel every formerly native dialog against a dedicated test database.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const browser = await chromium.launch({ headless: true });
const errors = [];
async function poll(check, message) {
    const end = Date.now() + 10000;
    while (Date.now() < end) { if (await check()) return; await new Promise(r => setTimeout(r, 60)); }
    throw new Error(message);
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/dialog-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 1024, height: 800 } });
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => { errors.push(`Native dialog: ${dialog.type()}`); dialog.dismiss(); });
        await page.goto(`${base}/dashboard/environments`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        const modal = page.locator('#confirm-dialog');
        const commit = async action => {
            const [response] = await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components.some(c => Object.keys(c.updates).length || c.calls.some(call => call.method !== '__dispatch'))), action()]);
            await response.finished();
            await page.waitForTimeout(100);
        };
        const open = async trigger => {
            await trigger.click();
            await poll(() => modal.evaluate(el => el.open), 'Confirmation did not open');
            assert.equal(await modal.locator('[data-confirm-cancel]').evaluate(el => el === document.activeElement), true);
            for (let i = 0; i < 5; i++) {
                await page.keyboard.press('Tab');
                assert.equal(await modal.evaluate(el => el.contains(document.activeElement)), true, 'Focus escaped the modal');
            }
        };
        const cancel = async trigger => {
            await open(trigger);
            await modal.locator('[data-confirm-cancel]').click();
            assert.equal(await modal.evaluate(el => el.open), false);
            await poll(() => trigger.evaluate(el => el === document.activeElement), 'Cancel did not return focus');
        };
        const confirm = async trigger => {
            await open(trigger);
            await commit(() => modal.locator('[data-confirm-accept]').click());
        };
        await commit(() => page.locator('.environment-list-items button').filter({ hasText: 'Dialog browser environment' }).click());
        const variable = page.locator('.environment-variable-table tbody tr').first();
        const removeVariable = variable.getByRole('button', { name: 'Delete', exact: true });
        await cancel(removeVariable);
        assert.equal(await variable.getByRole('textbox', { name: 'Variable key' }).inputValue(), 'DIALOG_TEST');
        await confirm(removeVariable);
        await poll(async () => await page.getByRole('textbox', { name: 'Variable key', exact: true }).count() === 0, 'Variable confirmation did not delete');
        await page.locator('.danger-zone > summary').click();
        const removeEnvironment = page.locator('.danger-zone').getByRole('button', { name: 'Delete', exact: true });
        await cancel(removeEnvironment);
        assert.equal(await page.locator('.environment-list-items button').filter({ hasText: 'Dialog browser environment' }).count(), 1);
        await confirm(removeEnvironment);
        await poll(async () => await page.locator('.environment-list-items button').filter({ hasText: 'Dialog browser environment' }).count() === 0, 'Environment confirmation did not delete');
        await page.goto(`${base}/dashboard`);
        await page.locator('.search-field input').fill('Dialog browser single');
        await poll(async () => await page.locator('.endpoint-card').count() === 1, 'Single endpoint search did not settle');
        const single = page.locator('.endpoint-card').first();
        await single.locator('.overflow-menu > summary').click();
        const removeEndpoint = single.getByRole('button', { name: 'Delete', exact: true });
        await cancel(removeEndpoint);
        assert.equal(await page.locator('.endpoint-card').count(), 1);
        await confirm(removeEndpoint);
        await poll(async () => await page.locator('.endpoint-card').count() === 0, 'Endpoint confirmation did not delete');
        await page.locator('.search-field input').fill('Dialog browser bulk');
        await poll(async () => await page.locator('.endpoint-card').count() === 2, 'Bulk endpoint search did not settle');
        for (const name of ['Dialog browser bulk-a', 'Dialog browser bulk-b']) await commit(() => page.getByRole('checkbox', { name: `Select ${name}`, exact: true }).check());
        const bulkDelete = page.locator('.selection-bar').getByRole('button', { name: 'Delete', exact: true });
        await cancel(bulkDelete);
        assert.equal(await page.locator('.endpoint-card').count(), 2);
        await confirm(bulkDelete);
        await poll(async () => await page.locator('.endpoint-card').count() === 0, 'Bulk confirmation did not delete');
        await page.goto(`${base}/dashboard/endpoints/${fixture.main}/edit?tab=response`);
        const reset = page.getByRole('button', { name: 'Reset sequence', exact: true });
        await cancel(reset);
        assert.match(await page.locator('.selection-settings').innerText(), /Currently on call 2/);
        await confirm(reset);
        await poll(async () => (await page.locator('.selection-settings').innerText()).includes('Currently on call 1'), 'Reset confirmation did not reset');
        await page.getByRole('button', { name: 'Endpoint history', exact: true }).click();
        const history = page.locator('#endpoint-history-dialog');
        const restore = history.locator('.revision-row').last().getByRole('button', { name: 'Restore', exact: true });
        const versions = await history.locator('.revision-row').count();
        await cancel(restore);
        assert.equal(await history.locator('.revision-row').count(), versions);
        await confirm(restore);
        await poll(async () => (await page.locator('#toast-region').innerText()).includes('restored') || (await history.locator('.revision-row').count()) > versions, 'Restore confirmation did not apply');
        if (await history.evaluate(el => el.open)) await page.keyboard.press('Escape');
        await page.goto(`${base}/dashboard/endpoints/${fixture.main}/edit?tab=response`);
        const response = page.locator('.selection-response-card').last();
        if (await response.locator('.overflow-menu > summary').count()) await response.locator('.overflow-menu > summary').click();
        const removeResponse = response.getByRole('button', { name: 'Delete', exact: true });
        await cancel(removeResponse);
        assert.equal(await page.locator('.selection-response-card').count(), 2);
        await confirm(removeResponse);
        await poll(async () => await page.locator('.selection-response-card').count() === 1, 'Response confirmation did not delete');
        await page.getByRole('tab', { name: /^Request/ }).click();
        await page.locator('#endpoint-priority').fill('42');
        const navigation = page.locator('.topnav a[href$="/logs"]');
        await cancel(navigation);
        assert.match(page.url(), /\/edit/);
        assert.equal(await page.locator('#endpoint-priority').inputValue(), '42');
        await open(navigation);
        await modal.locator('[data-confirm-accept]').click();
        await page.waitForURL('**/dashboard/logs');
        await page.goto(`${base}/dashboard/config`);
        await page.locator('#config-file').setInputFiles({ name: 'dialog-import.json', mimeType: 'application/json', buffer: Buffer.from(fixture.import) });
        try {
            await poll(() => page.getByRole('button', { name: 'Preview import', exact: true }).isEnabled(), 'Import upload did not finish');
        } catch (error) {
            throw new Error(`${error.message}: ${await page.locator('.field-error, .selected-file, .error-panel').allTextContents()}`);
        }
        await commit(() => page.locator('input[name=import-mode][value=upsert]').check());
        await commit(() => page.getByRole('button', { name: 'Preview import', exact: true }).click());
        const acknowledge = page.getByRole('checkbox', { name: 'I reviewed the warnings and want to continue.' });
        if (await acknowledge.count()) await commit(() => acknowledge.check());
        await commit(() => page.getByRole('button', { name: /^Confirm import/ }).click());
        await poll(() => page.getByRole('button', { name: 'Undo this import', exact: true }).isVisible(), 'Import did not finish');
        const undo = page.getByRole('button', { name: 'Undo this import', exact: true });
        await cancel(undo);
        assert.equal(await undo.isVisible(), true);
        await confirm(undo);
        await poll(async () => (await page.locator('.import-success').innerText()).includes('Import undone'), 'Undo confirmation did not apply');
        await page.close();
        console.log(`${theme}: confirm/cancel and focus trapping pass for all nine former native dialog sites`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
