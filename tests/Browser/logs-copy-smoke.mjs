// Fixture-writing test: use a fresh disposable database for each theme run.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const themes = process.env.MOCKDECK_BROWSER_THEME ? [process.env.MOCKDECK_BROWSER_THEME] : ['light', 'dark'];
assert.ok(themes.every(theme => ['light', 'dark'].includes(theme)));
const browser = await chromium.launch({ headless: true, args: ['--host-resolver-rules=MAP mockdeck-copy.test 127.0.0.1', '--no-proxy-server'] });
async function poll(check, message) {
    const end = Date.now() + 10000;
    while (Date.now() < end) { if (await check()) return; await new Promise(resolve => setTimeout(resolve, 60)); }
    throw new Error(message);
}
try {
    console.log(`Chromium ${browser.version()}`);
    for (const theme of themes) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/logs-copy-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'], viewport: { width: 1440, height: 1000 } });
        await context.addInitScript(theme => {
            localStorage.setItem('mockdeck-theme', theme);
            window.copyEvidence = { capture: [], bubble: 0, writes: [], rejections: [], rowActions: [] };
            const record = event => {
                const button = event.target.closest?.('[aria-label="Copy request curl"]');
                if (button) window.copyEvidence.capture.push({ text: button.dataset.copyText, curl: button.dataset.copyCurl,
                    connected: button.isConnected, expanded: button.closest('tr').getAttribute('aria-expanded') });
            };
            document.addEventListener('click', record, true);
            document.addEventListener('click', event => { if (event.target.closest?.('[aria-label="Copy request curl"]')) window.copyEvidence.bubble++; });
            if (navigator.clipboard?.writeText) {
                const write = navigator.clipboard.writeText.bind(navigator.clipboard);
                navigator.clipboard.writeText = async value => {
                    window.copyEvidence.writes.push(value);
                    try { return await write(value); }
                    catch (error) { window.copyEvidence.rejections.push({ name: error.name, message: error.message }); throw error; }
                };
            }
        }, theme);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (request.method() === 'POST' && request.url().includes('/update')) {
                const calls = request.postDataJSON()?.components?.flatMap(component => component.calls) ?? [];
                if (calls.length) console.log(`${theme}: Livewire calls ${JSON.stringify(calls.map(call => call.method))}`);
            }
        });
        const url = `${base}/b01/hellp?theme=${theme}`;
        assert.equal((await context.request.get(url)).status(), 404);
        await page.goto(`${base}/dashboard/logs`);
        const row = page.locator('.log-row').filter({ hasText: `/b01/hellp?theme=${theme}` });
        await row.click();
        await poll(async () => await row.getAttribute('aria-expanded') === 'true', 'Unmatched row did not expand');
        const copy = row.getByRole('button', { name: 'Copy request curl', exact: true });
        const expected = `curl --request GET '${url}'`;
        const results = [];
        const activate = async (label, action) => {
            await page.locator('[data-dismiss-toast]').evaluateAll(buttons => buttons.forEach(button => button.click()));
            const sentinel = `B01 sentinel ${theme} ${label}`;
            await page.evaluate(value => navigator.clipboard.writeText(value), sentinel);
            const before = await row.getAttribute('aria-expanded');
            await action();
            // Observe both actions before asserting, so a failure cannot hide the Enter result/control.
            await page.waitForTimeout(400);
            const actual = await page.evaluate(() => navigator.clipboard.readText());
            results.push({ label, before, after: await row.getAttribute('aria-expanded'), expected, actual });
            console.log(`${theme}: ${JSON.stringify(results.at(-1))}`);
            if (actual === expected) {
                assert.equal(await copy.innerText(), 'Copied');
                assert.ok(await page.locator('.toast-success').filter({ hasText: 'Mock curl copied to the clipboard.' }).isVisible());
                await poll(() => copy.isEnabled(), 'Copy button did not reset');
            }
        };
        await activate('click', () => copy.click());
        await copy.focus();
        await activate('Enter', () => copy.press('Enter'));
        console.log(`${theme}: event/clipboard evidence ${JSON.stringify(await page.evaluate(() => window.copyEvidence))}`);
        // Positive control in the same page/context, with the same granted clipboard permissions.
        await page.goto(`${base}/dashboard`);
        const registry = page.locator(`[data-copy-endpoint="${fixture.endpoint}"]`);
        await page.evaluate(() => navigator.clipboard.writeText('registry sentinel'));
        await registry.click();
        await poll(async () => await page.evaluate(() => navigator.clipboard.readText()) !== 'registry sentinel', 'Registry positive control failed');
        const registryText = await page.evaluate(() => navigator.clipboard.readText());
        assert.match(registryText, /\/b01\/hello/);
        console.log(`${theme}: registry positive control PASS: ${registryText}`);
        assert.deepEqual(results.map(result => result.actual), [expected, expected], 'Logs click and Enter must replace the sentinel');
        assert.deepEqual(results.map(result => result.after), ['true', 'true'], 'Copy must not toggle the row');
        await page.goto(`${base}/dashboard/logs`);
        for (const range of ['15m', 'all']) for (const density of ['compact', 'comfortable']) {
            await page.getByLabel('Filter by time range', { exact: true }).selectOption(range);
            await page.getByRole('group', { name: 'Request log row density' }).getByRole('button', { name: density, exact: false }).click();
            await row.click();
            await poll(async () => await row.getAttribute('aria-expanded') === 'true', 'Row did not expand after filters');
            await copy.focus();
            await activate(`${range}/${density}/Space`, () => copy.press('Space'));
            assert.equal(results.at(-1).actual, expected);
            assert.equal(await row.getAttribute('aria-expanded'), 'true');
            await row.click();
            await poll(async () => await row.getAttribute('aria-expanded') === 'false', 'Row did not collapse');
        }
        // A real automatic poll, then copy immediately from its rendered list.
        const updated = page.locator('.log-statusbar time');
        const beforePoll = await updated.getAttribute('datetime');
        const [refresh] = await Promise.all([page.waitForResponse(response => response.url().includes('/update') && response.request().postDataJSON()?.components?.some(component => component.calls.some(call => call.method === '$refresh'))), page.locator('[data-log-viewer]').scrollIntoViewIfNeeded()]);
        await refresh.finished();
        await poll(async () => await updated.getAttribute('datetime') !== beforePoll, 'Automatic poll did not render a new timestamp');
        await activate('after poll', () => copy.click());
        assert.equal(results.at(-1).actual, expected);
        // Reach the native button by Tab and inspect the token-based focus outline.
        await row.focus(); await page.keyboard.press('Tab');
        assert.equal(await copy.evaluate(node => node === document.activeElement), true);
        assert.equal(await copy.evaluate(node => getComputedStyle(node).outlineStyle), 'solid');
        assert.equal(await copy.evaluate(node => getComputedStyle(node).outlineWidth), '2px');
        // Deterministic rejected clipboard promise: preserve the real clipboard for readback.
        await page.evaluate(async () => {
            await navigator.clipboard.writeText('failure sentinel');
            navigator.clipboard.writeText = () => Promise.reject(new DOMException('Clipboard write denied for B-01 test.', 'NotAllowedError'));
            document.querySelectorAll('[data-toast]').forEach(node => node.remove());
        });
        await copy.click();
        await poll(() => page.locator('.toast-danger').isVisible(), 'Rejected clipboard write had no danger toast');
        assert.equal(await copy.innerText(), 'Copy failed');
        assert.equal(await page.locator('.toast-success').count(), 0);
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'failure sentinel');
        console.log(`${theme}: rejected clipboard promise PASS (NotAllowedError, danger toast, Copy failed, sentinel retained)`);
        await poll(() => copy.isEnabled(), 'Failed copy did not reset');
        // A genuine non-localhost HTTP origin exercises the existing execCommand fallback.
        const insecure = new URL(base); insecure.hostname = 'mockdeck-copy.test';
        const fallbackPage = await context.newPage();
        await fallbackPage.goto(`${insecure.origin}/dashboard/logs`);
        const fallbackCopy = fallbackPage.locator('.log-row').filter({ hasText: `/b01/hellp?theme=${theme}` }).getByRole('button', { name: 'Copy request curl', exact: true });
        assert.equal(await fallbackPage.evaluate(() => isSecureContext), false);
        assert.equal(await fallbackPage.evaluate(() => !!navigator.clipboard), false);
        await fallbackCopy.click();
        await poll(() => fallbackPage.locator('.toast-success').isVisible(), 'Insecure-origin fallback did not succeed');
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), expected);
        await poll(() => fallbackCopy.isEnabled(), 'Fallback copy did not reset');
        await fallbackPage.evaluate(() => { document.execCommand = () => false; document.querySelectorAll('[data-toast]').forEach(node => node.remove()); });
        await fallbackCopy.click();
        await poll(() => fallbackPage.locator('.toast-danger').isVisible(), 'Rejected fallback had no danger toast');
        assert.equal(await fallbackCopy.innerText(), 'Copy failed');
        assert.equal(await fallbackPage.locator('.toast-success').count(), 0);
        console.log(`${theme}: non-localhost HTTP fallback success and rejection PASS`);
        assert.deepEqual(errors, []);
        await context.close();
        console.log(`${theme}: Logs copy click/Enter/Space, density/range, real poll, focus, failure and fallback PASS`);
    }
} finally { await browser.close(); }
