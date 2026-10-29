// Writes fixtures only to an explicitly opted-in disposable instance.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw Error('Disposable instance and explicit fixture opt-in required.');
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP_BIN ?? 'php', ['tests/Support/critique-browser-fixture.php'], { encoding: 'utf8', env: process.env }));
const directory = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'supporting-workspaces');
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
const client = await request.newContext();
async function settle(page) { await page.waitForTimeout(450); }
try {
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('dialog', dialog => dialog.type() === 'beforeunload' ? dialog.accept() : dialog.dismiss());
        await page.addInitScript(t => localStorage.setItem('mockdeck-theme', t), theme);
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        const capture = async name => {
            await settle(page);
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${theme}/${name}: page overflow`);
            await page.screenshot({ path: resolve(directory, `${theme}-${page.viewportSize().width}-${name}.png`), fullPage: true, animations: 'disabled' });
        };
        for (const width of [1440, 1024, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit?tab=callback`);
            await page.getByRole('button', { name: `Configure callback for response ${fixture.response}`, exact: true }).click();
            await page.locator('#callback-url').waitFor();
            await page.locator('.callback-form').getByRole('button', { name: 'JSON', exact: true }).click();
            await capture('callback');
            assert.equal(await page.locator('#callback-url').inputValue(), 'https://current.example.test/hook');
            await page.goto(`${base}/dashboard/environments`);
            await page.locator(`.environment-list-items button[wire\\:click="selectEnvironment(${fixture.environment})"]`).click();
            await capture('environments');
            assert.ok((await page.locator('.environment-editor').innerText()).includes('Selected for editing'));
            await page.goto(`${base}/dashboard/logs?type=callbacks&time_range=all&request_log_id=critique-regression-request`);
            await page.getByRole('button', { name: `Attempt #${fixture.attempt}`, exact: true }).click();
            const inspector = page.locator('[data-activity-inspector]'); await inspector.waitFor();
            assert.match(await inspector.innerText(), /Receiver unavailable/);
            assert.ok(!(await inspector.innerText()).includes('hidden-regression-secret'));
            await page.waitForFunction(() => document.activeElement?.matches('[data-activity-inspector]'));
            await capture('callback-inspector');
            await inspector.getByRole('button', { name: 'Back to list' }).click();
            await inspector.waitFor({ state: 'detached' });
            await page.waitForFunction(id => document.activeElement?.dataset.activityTrigger === String(id), fixture.attempt);
            const path = `/supporting-mismatch-${theme}-${width}`;
            assert.equal((await client.get(base + path)).status(), 404);
            await page.goto(`${base}/dashboard/logs?type=requests`);
            await page.locator('.log-row').filter({ hasText: path }).first().press('Enter');
            await inspector.waitFor();
            assert.match(await inspector.innerText(), /No endpoint accepted/);
            await capture('request-inspector');
            await inspector.getByRole('button', { name: 'Back to list' }).click();
            await inspector.waitFor({ state: 'detached' });
            await page.goto(`${base}/dashboard/config`); await capture('transfer');
            await page.goto(`${base}/dashboard/docs`); await capture('documentation');
            await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
            await page.getByRole('button', { name: 'Endpoint history', exact: true }).click();
            const history = page.locator('#endpoint-history-dialog'); await history.waitFor();
            await history.getByRole('button', { name: 'Compare with current', exact: true }).first().click();
            await history.locator('.revision-diff-viewer').waitFor();
            assert.match(await history.locator('.revision-diff-viewer').innerText(), /Previous regression name/);
            await capture('history');
            await page.goto(`${base}/dashboard/not-a-page`); await capture('404');
        }
        assert.deepEqual(errors, []);
        await page.close(); console.log(`${theme}: Callback, both inspectors, focus, Environments, transfer, docs, History and 404 passed at 1440/1024/390/320.`);
    }
} finally { await client.dispose(); await browser.close(); }
