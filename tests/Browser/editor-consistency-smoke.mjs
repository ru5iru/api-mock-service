// Dedicated database only. Run with MOCKDECK_BROWSER_FIXTURE=1 and MOCKDECK_BASE_URL.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database and set MOCKDECK_BASE_URL.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const errors = [];
async function poll(check, message) {
    const end = Date.now() + 10000;
    while (Date.now() < end) { if (await check()) return; await new Promise(r => setTimeout(r, 60)); }
    throw new Error(message);
}
const intersects = (a, b) => a.x < b.x + b.width - 1 && a.x + a.width > b.x + 1 && a.y < b.y + b.height - 1 && a.y + a.height > b.y + 1;
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/wave1-browser-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 390, height: 600 } });
        page.on('pageerror', error => errors.push(`${theme}: ${error.stack ?? error.message}`));
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        const commit = action => Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST'), action()]);
        const tab = async name => {
            const scroll = await page.evaluate(() => scrollY);
            await page.getByRole('tab', { name: new RegExp('^' + name) }).click();
            await poll(async () => (await page.getByRole('tab', { name: new RegExp('^' + name) }).getAttribute('aria-selected')) === 'true', 'Tab did not activate');
            assert.equal(await page.evaluate(() => scrollY), scroll, 'Tab switch scrolled the page');
            assert.equal(await page.locator('[role=tabpanel]:visible').count(), 1, 'More than one panel is visible');
        };
        await tab('Response');
        const settings = page.locator('.selection-settings');
        const step = page.locator('#tab-response').locator('.section-status');
        await commit(() => settings.getByRole('button', { name: 'Rule-based', exact: true }).click());
        await poll(async () => (await step.getAttribute('class')).includes('attention'), 'Rule draft without fallback shows a checkmark');
        const checkToastOffset = async () => {
            await poll(() => page.evaluate(() => {
                const region = document.querySelector('#toast-region');
                region.style.removeProperty('bottom');
                return Math.abs(parseFloat(getComputedStyle(region).bottom) - Math.ceil(document.querySelector('#save-actions').getBoundingClientRect().height) - 16) < 1;
            }), 'Toast CSS offset did not follow changed Livewire action content');
        };
        await checkToastOffset();
        const responseDetails = page.locator('.selection-response-card').first().locator('.response-row-details > summary');
        if (await responseDetails.count()) await responseDetails.click();
        await commit(() => page.getByRole('radio', { name: `Use response ${fixture.first} as default fallback` }).check());
        await poll(async () => (await step.getAttribute('class')).includes('valid'), 'Valid rule fallback did not update Response step');
        await checkToastOffset();
        await page.setViewportSize({ width: 1024, height: 600 });
        await tab('Request');
        await commit(() => page.getByRole('button', { name: 'Edit request', exact: true }).click());
        const longCurl = "curl --request POST 'https://api.example.test/editor-audit?mode=yes' " + Array.from({ length: 24 }, (_, i) => `--header 'X-Audit-${i}: ${'long-value '.repeat(8)}'`).join(' ') + " --data '{\"name\":\"Audit\"}'";
        await page.locator('#raw-curl').fill(longCurl);
        await poll(async () => (await page.locator('#curl-status').innerText()).includes('24 headers'), 'Long curl did not parse');
        assert.ok((await page.locator('#raw-curl').boundingBox()).height <= 460);
        await commit(() => page.getByRole('button', { name: 'Done editing', exact: true }).click());
        await poll(async () => await page.locator('#raw-curl').count() === 0, 'Parsed request did not collapse');
        assert.match(await page.locator('.curl-summary').innerText(), /editor-audit/);
        await tab('Matching');
        if (!await page.getByRole('checkbox', { name: /Ignore all headers/ }).isChecked()) await commit(() => page.getByRole('checkbox', { name: /Ignore all headers/ }).check());
        assert.equal(await page.locator('.canonical-diff > div').count(), 5);
        assert.match(await page.locator('.canonical-diff').innerText(), /\+19 more/);
        const headerDetails = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'Header matching details' }) });
        assert.equal(await headerDetails.evaluate(el => el.open), false);
        for (const width of [999, 1000, 1001, 1024, 1440, 390]) {
            await page.setViewportSize({ width, height: 600 });
            await page.locator('#panel-matching').scrollIntoViewIfNeeded();
            await page.waitForTimeout(80);
            const bar = await page.locator('#save-actions').boundingBox();
            assert.ok(Math.abs(bar.y + bar.height - 600) < 1, `${theme}/${width}: action bar not viewport pinned`);
            if (width > 1000) {
                const pane = await page.locator('[data-editor-viewport]').boundingBox();
                assert.ok(pane.y + pane.height <= bar.y - 15, `${theme}/${width}: Signature viewport obstructed by actions`);
                assert.equal(await page.locator('.sticky-card').evaluate(el => getComputedStyle(el).overflowY), 'visible', 'Signature introduced a nested scrollbar');
            }
            await page.evaluate(() => window.MockDeck.toast('Offset audit'));
            const offset = await page.evaluate(() => {
                const region = document.querySelector('#toast-region');
                region.style.removeProperty('bottom');
                return { bottom: parseFloat(getComputedStyle(region).bottom), height: document.querySelector('#save-actions').getBoundingClientRect().height };
            });
            assert.ok(Math.abs(offset.bottom - offset.height - 16) < 1, 'CSS-only toast offset is out of scope');
            await page.locator('[data-dismiss-toast]').last().click();
            await headerDetails.locator('summary').click();
            await headerDetails.locator('summary').click();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${theme}/${width}: horizontal overflow`);
        }
        await page.setViewportSize({ width: 1024, height: 600 });
        await tab('Response');
        await commit(() => page.locator('.selection-response-card').first().getByRole('button', { name: 'Edit', exact: true }).click());
        const form = page.locator('.response-form');
        await commit(() => form.getByRole('button', { name: 'Template', exact: true }).click());
        for (const view of ['JSON', 'Builder']) {
            await commit(() => form.getByRole('button', { name: view, exact: true }).click());
            for (const root of view === 'Builder' ? ['Object', 'List of objects'] : ['JSON']) {
                if (root !== 'JSON') await commit(() => form.getByRole('button', { name: root, exact: true }).click());
                await form.locator('.template-preview').scrollIntoViewIfNeeded();
                assert.equal(await page.locator('.curl-summary').isVisible(), false, 'Curl content leaked into Response tab');
                await page.screenshot({ path: resolve(shots, `editor-${theme}-${root.replaceAll(' ', '-')}.png`) });
            }
        }
        await commit(() => form.getByRole('button', { name: 'Object', exact: true }).click());
        await commit(() => form.locator('.schema-type select').first().selectOption('faker'));
        const picker = form.locator('[data-faker-picker]').first();
        for (const width of [1001, 1000, 999, 390]) {
            await page.setViewportSize({ width, height: 600 });
            await picker.locator('summary').scrollIntoViewIfNeeded();
            await picker.locator('summary').evaluate(el => { const pane = document.querySelector('[data-editor-viewport]'); pane.scrollTop += el.getBoundingClientRect().top - 330; });
            await picker.locator('summary').click();
            await page.waitForTimeout(220);
            const box = await picker.locator('.faker-picker-panel').boundingBox();
            const anchor = await picker.locator('summary').boundingBox();
            const bar = await page.locator('#save-actions').boundingBox();
            assert.ok(box.x >= 15 && box.x + box.width <= width - 15, 'Faker picker escapes horizontal bounds');
            assert.ok(box.y >= 15, 'Faker picker escapes the viewport top');
            assert.ok(box.y + box.height <= bar.y - 15, 'Faker picker overlaps fixed actions');
            assert.equal(intersects(box, anchor), false, 'Faker picker covers its trigger');
            const hit = await picker.locator('.faker-picker-panel').evaluate(el => {
                const box = el.getBoundingClientRect();
                return el.contains(document.elementFromPoint(box.left + 12, box.top + 12));
            });
            assert.equal(hit, true, 'Picker is trapped below another stacking context');
            await page.keyboard.press('Escape');
        }
        await page.setViewportSize({ width: 1024, height: 600 });
        for (const selector of ['.environment-switcher', '.theme-menu', '.user-menu']) {
            const menu = page.locator(selector).first();
            await menu.locator('summary').click();
            await page.waitForTimeout(160);
            const panel = menu.locator(':scope > :not(summary)');
            const box = await panel.boundingBox();
            assert.ok(box.y + box.height < (await page.locator('#save-actions').boundingBox()).y);
            assert.equal(await panel.evaluate(el => { const b = el.getBoundingClientRect(); return el.contains(document.elementFromPoint(b.left + 10, b.top + 10)); }), true);
            await page.keyboard.press('Escape');
        }
        await page.evaluate(() => document.querySelector('#shortcut-dialog').showModal());
        assert.equal(await page.locator('#shortcut-dialog').evaluate(el => { const b = el.getBoundingClientRect(); return el.contains(document.elementFromPoint(b.left + 30, b.top + 30)); }), true);
        await page.locator('#shortcut-dialog').getByRole('button', { name: 'Close shortcut help' }).click();
        await page.clock.install();
        await page.clock.pauseAt(new Date(Date.now() + 1000));
        await page.evaluate(() => { document.querySelector('#toast-region').replaceChildren(); window.MockDeck.toast('Expires'); });
        await page.mouse.move(0, 0);
        await page.clock.runFor(5999);
        assert.equal(await page.locator('[data-toast]').count(), 1);
        await page.clock.runFor(1);
        assert.equal(await page.locator('[data-toast]').count(), 0);
        await page.evaluate(() => window.MockDeck.toast('Pause me'));
        await page.clock.runFor(2000);
        await page.locator('[data-toast]').hover();
        await page.clock.runFor(7000);
        assert.equal(await page.locator('[data-toast]').count(), 1, 'Hovered toast expired');
        await page.mouse.move(0, 0);
        await page.clock.runFor(3000);
        assert.equal(await page.locator('[data-toast]').count(), 1);
        await page.clock.runFor(1100);
        assert.equal(await page.locator('[data-toast]').count(), 0);
        await page.close();
        console.log(`${theme}: step validity, density, sticky offsets, preview isolation, menu bounds/top layer, dialog and toast timers pass`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
