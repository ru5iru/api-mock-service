// Run against a disposable dashboard; this test explicitly creates variables/endpoints.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`)('playwright') : require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw new Error('Set MOCKDECK_BASE_URL to a disposable dashboard.');
const screenshots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(screenshots, { recursive: true });
const browser = await chromium.launch({ headless: true });
try {
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/dashboard/config`);
        await page.evaluate(t => localStorage.setItem('mockdeck-theme', t), theme);
        await page.reload();
        await page.getByRole('button', { name: 'Postman', exact: true }).click();
        const suffix = `${theme}_${Date.now()}`;
        const token = `stg_token_${suffix}`;
        const cart = `cartId_${suffix}`;
        const path = `/variable-review-${suffix}`;
        const collection = {
            info: { name: 'Variable review', schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json' },
            variable: [{ key: cart, value: 'collection-cart' }],
            item: [{ name: 'Cart', request: { method: 'GET', url: path, header: [{ key: 'Authorization', value: `Bearer {{${token}}}` }] },
                response: [{ name: 'Success', code: 200, body: JSON.stringify({ cart: `{{${cart}}}`, email: '{{$randomEmail}}', unknown: '{{$someObscureVar}}' }) }] }],
        };
        await page.locator('#config-file').setInputFiles({ name: 'variable-review.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(collection)) });
        await page.waitForFunction(() => !document.querySelector('form[wire\\:submit="preview"] button[type="submit"]').disabled);
        await page.getByRole('button', { name: 'Preview import', exact: true }).click();
        await page.getByText('2 variables referenced, 1 without a known value', { exact: true }).waitFor();
        assert.equal(await page.getByRole('checkbox', { name: `Mark ${token} as secret`, exact: true }).isChecked(), true);
        await page.locator('.import-table details').filter({ hasText: 'Variable fields' }).locator('summary').click();
        await page.getByLabel('Environment for creation / resolution').selectOption(await page.locator('#postman-variable-environment option').nth(1).getAttribute('value'));
        await page.getByRole('button', { name: 'Create these as Environment variables', exact: true }).waitFor({ state: 'visible' });
        await page.waitForFunction(() => !document.querySelector('[wire\\:click="createEnvironmentVariables"]').disabled);
        await page.setViewportSize({ width: 1440, height: 1500 });
        await page.locator('.import-preview').screenshot({ path: resolve(screenshots, `postman-variable-review-${theme}.png`) });
        await page.getByRole('button', { name: 'Create these as Environment variables', exact: true }).click();
        const dialog = page.locator('#confirm-dialog');
        await dialog.waitFor({ state: 'visible' });
        await dialog.locator('[data-confirm-accept]').click();
        await page.getByText(/2 variables created/).waitFor();
        await page.getByLabel('Variable matching', { exact: true }).selectOption('resolve');
        await page.waitForFunction(() => [...document.querySelectorAll('select[id^="variable-field-"] option:first-child')].every(n => n.textContent.includes('resolve')));
        for (const width of [1440, 1024, 767, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `${theme}/${width}: page overflow`);
            const misaligned = await page.locator('.details-chevron').evaluateAll(icons => icons.filter(icon => icon.getClientRects().length).filter(icon => {
                const box = icon.getBoundingClientRect(), trigger = icon.parentElement, parent = trigger.getBoundingClientRect(), style = getComputedStyle(trigger);
                const center = (parent.top + parent.bottom + parseFloat(style.paddingTop) - parseFloat(style.paddingBottom) + parseFloat(style.borderTopWidth) - parseFloat(style.borderBottomWidth)) / 2;
                return icon.tagName.toLowerCase() !== 'svg' || Math.abs(box.width - 16) > 1 || Math.abs(box.height - 16) > 1 || Math.abs((box.top + box.bottom) / 2 - center) > 1;
            }).map(icon => icon.parentElement.textContent.trim()));
            assert.deepEqual(misaligned, [], `${theme}/${width}: Postman preview chevrons must align with labels`);
            await page.screenshot({ path: resolve(screenshots, `postman-variables-${theme}-${width}.png`), fullPage: true });
        }
        await page.setViewportSize({ width: 1440, height: 1500 });
        for (const button of await page.locator('[data-dismiss-toast]').all()) { await button.click(); }
        await page.locator('.import-table details').filter({ hasText: 'warnings for Cart' }).locator('summary').click();
        await page.locator('.import-preview').screenshot({ path: resolve(screenshots, `postman-variable-preview-${theme}.png`) });
        await page.getByRole('button', { name: /Confirm import/ }).click();
        await page.getByRole('heading', { name: 'Import applied atomically', exact: true }).waitFor();
        const invocation = await browser.newContext();
        const reply = await invocation.request.get(`${base}${path}`, { headers: { Authorization: 'Bearer ' } });
        assert.equal(reply.status(), 200);
        const body = await reply.json();
        await invocation.close();
        assert.equal(body.cart, 'collection-cart');
        assert.match(body.email, /^[^\s@]+@[^\s@]+\.[^\s@]+$/);
        assert.equal(body.unknown, '{{$someObscureVar}}');
        assert.deepEqual(errors, []);
        await page.close();
    }
    console.log('Postman variable review, explicit creation, resolution and response rendering passed in both themes at four widths.');
} finally { await browser.close(); }
