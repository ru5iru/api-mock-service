// MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/postman-import.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`)('playwright') : require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw new Error('Set MOCKDECK_BASE_URL to a running dashboard.');
const screenshots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(screenshots, { recursive: true });
const browser = await chromium.launch({ headless: true });
try {
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/dashboard/config`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        await page.getByRole('button', { name: 'Postman', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[aria-label="Import format"] [aria-pressed="true"]')?.textContent === 'Postman');
        const path = `browser-postman-${theme}-${Date.now()}`;
        const collection = {
            info: { name: 'Browser collection', schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json' },
            item: [{ name: 'Auth', item: [{ name: 'Users', item: [{ name: 'Variable request',
                request: { method: 'POST', url: { path: [path, '{{id}}'], query: [{ key: 'nonce', value: '{{nonce}}' }] },
                    header: [{ key: 'Authorization', value: 'Bearer browser-secret' }], body: { mode: 'raw', raw: '{"ok":true} // comment' } },
                event: [{ listen: 'prerequest', script: { exec: ['throw new Error("NEVER_EXECUTED");'] } }],
                response: [{ name: 'Imported success', code: 200, body: '{"ok":true}', header: [{ key: 'Set-Cookie', value: 'session=browser-secret' }] }],
            }] }] }],
        };
        await page.locator('#config-file').setInputFiles({ name: 'collection.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(collection)) });
        const preview = page.getByRole('button', { name: 'Preview import', exact: true });
        await preview.waitFor();
        await page.waitForFunction(() => !document.querySelector('form[wire\\:submit="preview"] button[type="submit"]').disabled);
        await preview.click();
        await page.getByText('Possible credentials detected', { exact: true }).waitFor();
        await page.getByRole('button', { name: 'Mask detected secrets', exact: true }).click();
        await page.getByText('Detected credentials are masked as REPLACE_ME in this import.', { exact: true }).waitFor();
        await page.locator('.import-table details > summary').click();
        await page.getByText('Query parameter nonce excluded: contains a Postman variable.', { exact: true }).waitFor();
        assert.ok(await page.getByText('Body is not valid JSON — matched as raw text.', { exact: true }).isVisible());
        assert.ok(await page.getByText('Pre-request script present (1 lines); not imported or executed.', { exact: true }).isVisible());
        const confirm = page.getByRole('button', { name: /Confirm import/ });
        assert.equal(await confirm.isEnabled(), true, 'Mapping warnings must not block confirmation');
        for (const width of [1440, 1024, 767, 390]) {
            await page.setViewportSize({ width, height: 850 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `${theme}/${width}: document overflow`);
            await page.screenshot({ path: resolve(screenshots, `postman-${theme}-${width}.png`), fullPage: true });
        }
        await confirm.click();
        await page.getByRole('heading', { name: 'Import applied atomically', exact: true }).waitFor();
        await page.locator('.imported-links a').first().click();
        await page.waitForURL('**/endpoints/*/edit');
        assert.deepEqual(errors, [], `${theme}: browser errors`);
        await page.close();
    }
    console.log('Postman import: preview, masking, warnings, apply and responsive layout passed in both themes.');
} finally { await browser.close(); }
