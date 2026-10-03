// Dedicated database only: seed fixtures, then exercise Wave 1 controls in both themes.
// MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/wave1-smoke.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
const runtimeModules = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const require = runtimeModules ? createRequire(`${runtimeModules}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Set MOCKDECK_BASE_URL and MOCKDECK_BROWSER_FIXTURE=1, using a dedicated test database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots');
await mkdir(shots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const failures = [];
async function poll(check, message) {
    const deadline = Date.now() + 10000;
    while (Date.now() < deadline) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/wave1-browser-fixture.php'], {
            env: process.env, encoding: 'utf8',
        }));
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        page.on('pageerror', error => failures.push(`${theme}: ${error.message}`));
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        await page.getByRole('tab', { name: /^Response/ }).click();
        const settings = page.locator('.selection-settings');
        const rows = page.locator('.selection-response-card');
        const row = id => page.locator(`[wire\\:key="response-${id}"]`);
        const commit = async action => {
            const [response] = await Promise.all([page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST'), action()]);
            await response.finished();
        };
        const openDetails = async id => {
            const details = row(id).locator('.response-row-details');
            if (!await details.evaluate(el => el.open)) await details.locator(':scope > summary').click();
        };
        const save = () => commit(() => settings.getByRole('button', { name: 'Save selection', exact: true }).click());
        await commit(() => settings.getByRole('button', { name: 'Sequence', exact: true }).click());
        await poll(() => page.locator('#sequence-on-exhaust').isVisible(), 'Sequence controls missing');
        await page.locator('#sequence-on-exhaust').selectOption('loop');
        await save();
        await openDetails(fixture.first);
        await openDetails(fixture.second);
        await row(fixture.first).getByRole('button', { name: `Move response ${fixture.first} later`, exact: true }).focus();
        await commit(() => page.keyboard.press('Enter'));
        await poll(async () => (await rows.first().getAttribute('wire:key')) === `response-${fixture.second}`, 'Keyboard reorder failed');
        // Pointer reordering uses the compact row state, with both handles in the tab viewport.
        for (const id of [fixture.first, fixture.second]) {
            const details = row(id).locator('.response-row-details');
            if (await details.evaluate(el => el.open)) await details.locator(':scope > summary').click();
        }
        while (await page.locator('[data-dismiss-toast]').count()) await page.locator('[data-dismiss-toast]').first().click();
        await row(fixture.second).scrollIntoViewIfNeeded();
        await commit(() => row(fixture.second).locator('[data-schema-drag-handle]').dragTo(row(fixture.first), { sourcePosition: { x: 4, y: 4 }, targetPosition: { x: 70, y: 15 } }));
        await poll(async () => (await rows.first().getAttribute('wire:key')) === `response-${fixture.first}`, 'Pointer drag reorder failed');
        await save();
        const served = await page.request.get(`${base}/wave1-browser`);
        assert.equal(served.status(), 200);
        assert.deepEqual(await served.json(), { response: 'first' });
        await page.reload();
        await page.getByRole('tab', { name: /^Response/ }).click();
        await poll(async () => (await settings.innerText()).includes('Currently on call 2 of 2'), 'Live request did not advance sequence readout');
        await settings.getByRole('button', { name: 'Reset sequence', exact: true }).click();
        const confirm = page.locator('#confirm-dialog');
        assert.match(await confirm.innerText(), /only\?/);
        await commit(() => confirm.locator('[data-confirm-accept]').click());
        await poll(async () => (await settings.innerText()).includes('Currently on call 1 of 2'), 'Reset did not update the sequence readout');
        await page.screenshot({ path: resolve(shots, `${theme}-wave1-sequence.png`), fullPage: true });
        await commit(() => settings.getByRole('button', { name: 'Rule-based', exact: true }).click());
        await save();
        await poll(async () => (await settings.locator('[role="alert"]').innerText()).includes('exactly one'), 'Missing fallback validation');
        await openDetails(fixture.first);
        await openDetails(fixture.second);
        await commit(() => row(fixture.second).getByRole('radio', { name: `Use response ${fixture.second} as default fallback` }).check());
        await row(fixture.first).locator('summary').filter({ hasText: 'Conditions' }).click();
        await commit(() => row(fixture.first).getByRole('button', { name: 'Add condition', exact: true }).click());
        const disclosure = row(fixture.first).locator('.selection-response-details > details');
        assert.equal(await disclosure.evaluate(node => node.open), true, 'Conditions collapsed after adding a rule');
        const condition = row(fixture.first).locator('.rule-condition-row');
        await condition.getByLabel('Field type').selectOption('header');
        await commit(() => condition.getByLabel('Operator').selectOption('exists'));
        assert.equal(await disclosure.evaluate(node => node.open), true, 'Conditions collapsed after live operator change');
        await poll(() => condition.getByLabel('Value', { exact: true }).isDisabled(), 'Exists operator should disable Value');
        await commit(() => condition.getByLabel('Operator').selectOption('equals'));
        assert.equal(await disclosure.evaluate(node => node.open), true, 'Conditions collapsed after returning to equals');
        await condition.getByLabel('Field name', { exact: true }).fill('X-Mode');
        await condition.getByLabel('Value', { exact: true }).fill('yes');
        await save();
        await poll(async () => (await settings.locator('.selection-preview').innerText()).includes('X-Mode'), 'Rule preview not updated');
        await row(fixture.second).locator('.overflow-menu > summary').click();
        await row(fixture.second).getByRole('button', { name: 'Delete', exact: true }).click();
        assert.match(await confirm.innerText(), /fallback/);
        await commit(() => confirm.locator('[data-confirm-accept]').click());
        await poll(async () => (await settings.locator('[role="alert"]').innerText()).includes('Deleting the fallback would leave rule mode invalid'), 'Blocked fallback deletion has no explanation');
        assert.equal(await row(fixture.second).count(), 1, 'Saved fallback was deleted');
        await commit(() => row(fixture.first).getByRole('radio', { name: `Use response ${fixture.first} as default fallback` }).check());
        await save();
        assert.equal(await row(fixture.first).getByRole('radio', { name: `Use response ${fixture.first} as default fallback` }).isChecked(), true);
        assert.equal(await row(fixture.second).getByRole('radio', { name: `Use response ${fixture.second} as default fallback` }).isChecked(), false);
        await page.screenshot({ path: resolve(shots, `${theme}-wave1-rules.png`), fullPage: true });
        await commit(() => row(fixture.first).getByRole('button', { name: 'Edit', exact: true }).click());
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.first}`), 'Template response edit did not finish');
        const form = page.locator('.response-form');
        await commit(() => form.getByRole('button', { name: 'Template', exact: true }).click());
        await commit(() => form.getByRole('button', { name: 'JSON', exact: true }).click());
        await page.locator('#response-template').fill('{"method":"$request.method","id":"$request.id","missing":"$request.json.noSuchPath","name":"{{env.NAME}}"}');
        await poll(() => form.getByRole('button', { name: 'Builder', exact: true }).isDisabled(), 'Context template allowed lossy Builder');
        await commit(() => form.getByRole('button', { name: 'Regenerate', exact: true }).click());
        const preview = form.locator('.template-preview');
        await poll(async () => (await preview.innerText()).includes('Wave One sample'), 'Synthetic preview did not resolve context');
        assert.match(await preview.innerText(), /synthetic samples/);
        const sample = JSON.parse(await preview.locator('pre').innerText());
        assert.equal(sample.method, 'POST');
        assert.equal(sample.id, 'preview');
        assert.equal(sample.missing, null);
        await page.screenshot({ path: resolve(shots, `${theme}-wave1-template.png`), fullPage: true });
        for (const width of [1440, 1024, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.screenshot({ path: resolve(shots, `${theme}-wave1-${width}.png`), fullPage: true });
            await poll(async () => await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${theme}: document overflows at ${width}px`);
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
        while (await page.locator('[data-dismiss-toast]').count()) await page.locator('[data-dismiss-toast]').first().click();
        // Regression: sticky Save changes previously submitted only EndpointForm and lost response drafts.
        await commit(() => row(fixture.first).getByRole('button', { name: 'Edit', exact: true }).click());
        await commit(() => form.getByRole('button', { name: 'Static', exact: true }).click());
        await page.locator('#response-body').fill('{"response":"saved globally"}');
        const globalSave = async () => {
            await page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).click();
            await poll(async () => await page.locator('.response-form h3').innerText() === 'Add response', 'Global save did not finish response save/navigation');
        };
        await globalSave();
        await page.reload();
        await page.getByRole('tab', { name: /^Response/ }).click();
        assert.equal(await rows.count(), 2, 'Global save created an unwanted response');
        await commit(() => row(fixture.first).getByRole('button', { name: 'Edit', exact: true }).click());
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.first}`), 'Saved response edit did not finish');
        assert.deepEqual(JSON.parse(await page.locator('#response-body').inputValue()), { response: 'saved globally' }, 'Global save lost the edited response');
        await page.locator('#response-body').fill('{"response":"first"}');
        await page.locator('#response-weight').fill('0');
        await page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).click();
        await poll(async () => (await page.locator('.response-form [role="alert"]').innerText()).includes('not saved'), 'Global save failed without visible validation');
        assert.equal(await page.locator('#response-body').inputValue(), '{"response":"first"}', 'Invalid draft was lost');
        await poll(() => page.locator('[data-sticky-action-bar]').getByRole('button', { name: 'Save changes', exact: true }).isEnabled(), 'Global save stayed disabled after validation failure');
        await page.locator('#response-weight').fill('1');
        await globalSave();
        await page.reload();

        await page.close();
        console.log(`${theme}: selection, drag/keyboard reorder, reset, rule validation/fallback, synthetic preview, global save/validation and responsive checks pass`);
    }
    assert.deepEqual(failures, [], 'Browser reported JavaScript errors');
} finally { await browser.close(); }
