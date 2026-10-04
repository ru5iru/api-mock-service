// Dedicated fixture database only. Exercises actual Livewire saves and serving in both themes.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'wave2');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const errors = [];
async function poll(check, message) {
    const end = Date.now() + 12000;
    while (Date.now() < end) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/wave2-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', error => errors.push(`${theme}: ${error.message}`));
        await page.addInitScript(theme => localStorage.setItem('mockdeck-theme', theme), theme);
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        const commit = async action => {
            const [response] = await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components.some(c => Object.keys(c.updates).some(key => key !== 'activeTab') || c.calls.some(call => !['touchSection', '__dispatch'].includes(call.method)))), action()]);
            assert.equal(response.status(), 200);
            await response.finished();
            await page.waitForTimeout(100);
        };
        await commit(() => page.getByLabel('Use path parameters', { exact: true }).check());
        await commit(() => page.getByRole('button', { name: 'Use parameter for segment 3', exact: true }).click());
        await commit(() => page.getByLabel('Parameter name for segment 3', { exact: true }).fill('id'));
        await poll(async () => (await page.locator('.response-manager').getAttribute('data-context-catalog')).includes('request.path.id'), 'Unsaved path context did not reach response preview');
        await page.getByLabel('Parameter name for segment 3', { exact: true }).scrollIntoViewIfNeeded();
        await page.screenshot({ path: resolve(shots, `${theme}-path-editor.png`) });
        await page.getByRole('tab', { name: /^Matching/ }).click();
        await commit(() => page.getByLabel('Exclude query noise', { exact: true }).check());
        const headerDetails = page.locator('details').filter({ has: page.getByLabel('Exclude header X-Trace', { exact: true }) });
        await headerDetails.locator(':scope > summary').click();
        await commit(() => page.getByLabel('Exclude header X-Trace', { exact: true }).check());
        assert.equal(await page.locator('.exclusion-hint').count(), 0, 'First-exclusion hint persists');
        assert.equal(await page.locator('.parsed-policy-list del').filter({ hasText: 'noise' }).count(), 1);
        await commit(() => page.getByLabel('Ignore all headers', { exact: false }).check());
        await poll(() => page.getByLabel('Exclude header X-Trace', { exact: true }).isDisabled(), 'Coarse policy did not disable individual header controls');
        assert.match(await headerDetails.innerText(), /redundant/);
        await commit(() => page.getByLabel('Ignore all headers', { exact: false }).uncheck());
        await page.getByRole('tab', { name: /^Response/ }).click();
        await commit(() => page.locator('.selection-response-card').getByRole('button', { name: 'Edit', exact: true }).click());
        const form = page.locator('.response-form');
        await poll(async () => (await form.locator('h3').innerText()).includes(`#${fixture.response}`), 'Response not selected');
        await commit(() => form.getByRole('button', { name: 'Regenerate', exact: true }).click());
        await poll(async () => (await form.innerText()).includes('sample_id'), 'Pattern preview did not render a synthetic path value');
        const fault = form.locator('details').filter({ has: page.locator('#response-fault-type') });
        assert.equal(await fault.evaluate(el => el.open), false, 'Fault disclosure should default closed');
        await fault.locator(':scope > summary').click();
        await commit(() => fault.getByLabel('Enable fault injection', { exact: true }).check());
        await commit(() => page.locator('#response-fault-type').selectOption('timeout'));
        assert.equal(await page.locator('#response-fault-delay-min').inputValue(), '60000');
        assert.match(await fault.innerText(), /Approximation/);
        await commit(() => page.locator('#response-fault-type').selectOption('malformed_body'));
        await page.locator('#response-fault-probability').fill('100');
        await page.locator('#response-fault-type').scrollIntoViewIfNeeded();
        await page.screenshot({ path: resolve(shots, `${theme}-fault-editor.png`) });
        const editorId = await page.locator('.endpoint-editor').getAttribute('wire:id');
        await commit(() => page.locator('#save-actions button[type=submit]').click());
        await poll(async () => (await page.locator('.endpoint-editor').getAttribute('wire:id')) !== editorId, 'Global save did not finish');
        await page.reload();
        const served = await page.request.get(`${base}/wave2-browser/users/abc?keep=1&noise=new`, { headers: { 'X-Trace': 'new' } });
        assert.equal(served.status(), 200);
        assert.equal(await served.text(), '{"mockdeck_fault":');
        const api = `${base}/api/v1/verify/endpoints/${fixture.uuid}`;
        assert.equal((await page.request.get(`${api}/calls?environment=${encodeURIComponent(fixture.environment)}`)).status(), 401);
        const verified = await page.request.get(`${api}/calls?environment=${encodeURIComponent(fixture.environment)}`, { headers: { Authorization: `Bearer ${fixture.token}` } });
        assert.equal(verified.status(), 200);
        assert.equal((await verified.json()).total_match_count, 1);
        await page.getByRole('tab', { name: /^Response/ }).click();
        await commit(() => page.locator('.selection-response-card').getByRole('button', { name: 'Edit', exact: true }).click());
        await form.locator('details').filter({ has: page.locator('#response-fault-type') }).locator(':scope > summary').click();
        assert.equal(await page.locator('#response-fault-type').inputValue(), 'malformed_body');
        for (const [width, height] of [[1440,1000],[1001,780],[1000,650],[390,844]]) {
            await page.setViewportSize({ width, height });
            for (const name of ['Request','Matching','Response','Callback']) {
                await page.getByRole('tab', { name: new RegExp(`^${name}`) }).click();
                await page.locator('[data-editor-viewport]').evaluate(el => el.scrollTop = el.scrollHeight);
                await poll(async () => {
                    const bar = await page.locator('#save-actions').boundingBox();
                    const pane = await page.locator('[data-editor-viewport]').boundingBox();
                    return pane.y + pane.height <= bar.y + 2 && Math.abs(bar.y + bar.height - height) <= 2;
                }, `${theme}/${width}/${name}: editor bounds did not settle`);
                const bar = await page.locator('#save-actions').boundingBox();
                const pane = await page.locator('[data-editor-viewport]').boundingBox();
                assert.ok(pane.y + pane.height <= bar.y + 2, `${theme}/${width}/${name}: panel under action bar`);
                assert.ok(Math.abs(bar.y + bar.height - height) <= 2, `${theme}/${width}/${name}: action bar not pinned`);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${theme}/${width}/${name}: horizontal overflow`);
                await page.screenshot({ path: resolve(shots, `${theme}-${width}-${name.toLowerCase()}.png`) });
            }
        }
        await page.close();
        console.log(`${theme}: exclusions, pattern draft samples, fault save/serve, token counters and all-tab responsive bounds pass`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
