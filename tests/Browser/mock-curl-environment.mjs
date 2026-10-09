// Requires a disposable dashboard seeded with tests/Support/mock-curl-environment-fixture.php.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const require = createRequire(import.meta.url);
const { chromium } = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`)('playwright') : require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || !process.env.MOCKDECK_COPY_FIXTURE) throw new Error('Set MOCKDECK_BASE_URL and MOCKDECK_COPY_FIXTURE from the disposable fixture.');
const fixture = JSON.parse(process.env.MOCKDECK_COPY_FIXTURE);
const browser = await chromium.launch({ headless: true });
try {
    for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(`${base}/dashboard`);
        await page.evaluate(t => localStorage.setItem('mockdeck-theme', t), theme);
        await page.reload();
        const copy = page.locator(`[data-copy-endpoint="${fixture.endpoint}"]`);
        assert.equal(await copy.getAttribute('data-copy-curl'), '');
        assert.ok(!(await page.content()).includes("saved-token's-value"), 'Secret must not be pre-rendered.');
        await copy.click();
        await page.getByText('Mock curl copied to the clipboard.', { exact: true }).last().waitFor();
        const curl = await page.evaluate(() => navigator.clipboard.readText());
        assert.ok(curl.includes('loginId=user%2Bone%20%26%2042%25'));
        assert.ok(!curl.includes('%7B%7B') && !curl.includes('{{copy_token}}'));
        assert.ok(curl.includes("saved-token'\\''s-value"));
        // Executing the copied command verifies shell quoting, wire values and
        // the actual imported endpoint's exclusions together.
        const output = execFileSync('bash', ['-c', `${curl} --silent --show-error --max-time 10 --noproxy '*'`], { encoding: 'utf8' });
        assert.deepEqual(JSON.parse(output), { copied: true });
        const api = `${base}/api/environments/${fixture.environment}/variables/${fixture.login_variable}`;
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') };
        const patch = await context.request.patch(api, { headers, data: { key: 'copy_login_id', value: 'latest +', is_secret: false } });
        assert.equal(patch.status(), 200);
        await copy.click();
        let latest;
        for (let attempt = 0; attempt < 100; attempt++) {
            latest = await page.evaluate(() => navigator.clipboard.readText());
            if (latest.includes('loginId=latest%20%2B')) break;
            await page.waitForTimeout(50);
        }
        assert.ok(latest.includes('loginId=latest%20%2B'), 'Copy must use the latest saved value.');
        const missing = await context.request.patch(api, { headers, data: { key: `renamed_copy_login_id_${fixture.login_variable}`, is_secret: false } });
        assert.equal(missing.status(), 200);
        await copy.click();
        await page.getByText(/Cannot copy mock curl:.*copy_login_id/).last().waitFor();
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), latest, 'Missing values leave the clipboard unchanged.');
        const restore = await context.request.patch(api, { headers, data: { key: 'copy_login_id', value: 'user+one & 42%', is_secret: false } });
        assert.equal(restore.status(), 200);
        assert.deepEqual(errors, []);
        await context.close();
    }
    console.log('Resolved query/header clipboard output, latest values, missing-key feedback, secret-free rows and live invocation pass in both themes.');
} finally { await browser.close(); }
