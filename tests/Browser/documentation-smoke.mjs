// MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/documentation-smoke.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';

const require = createRequire(import.meta.url);
const { chromium } = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`)('playwright')
    : require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw new Error('Set MOCKDECK_BASE_URL to a running dashboard with access to Documentation.');
const browser = await chromium.launch({
    headless: true,
    ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}),
});
const directory = process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots/documentation';
await mkdir(directory, { recursive: true });

try {
    for (const theme of ['light', 'dark']) {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(`${base}/dashboard/docs`);
            await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
            await page.reload();

            for (const id of ['service-startup', 'local-https', 'connection-troubleshooting']) {
                assert.equal(await page.locator(`#${id}`).count(), 1);
                await page.locator(`a[href="#${id}"]`).click();
                await page.locator(`#${id}`).screenshot({ path: `${directory}/${theme}-${width}-${id}.png` });
            }
            for (const details of await page.locator('#local-https details[data-disclosure]').all()) {
                await details.locator('summary').click();
            }
            assert.equal(await page.locator('#local-https summary[aria-expanded="true"]').count(), 2);
            const overflow = await page.evaluate(() => Array.from(document.querySelectorAll(
                '#service-startup pre, #local-https pre, #connection-troubleshooting pre',
            )).filter(element => element.scrollWidth > element.clientWidth + 1
                || element.getBoundingClientRect().right > innerWidth + 1)
                .map(element => element.textContent));
            assert.deepEqual(overflow, []);
            assert.ok((await page.locator('#local-https').innerText()).includes(
                'certutil -user -addstore Root "$env:USERPROFILE\\Desktop\\mockdeck-rootCA.pem"',
            ));
            assert.deepEqual(errors, []);
            console.log(`${theme} ${width}: setup sections, disclosures, commands and code wrapping passed`);
            await page.close();
        }
    }
} finally {
    await browser.close();
}
