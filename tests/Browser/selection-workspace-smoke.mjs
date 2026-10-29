// Disposable instance only. Exercises the real selection/fault engines through HTTP.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { openEditorSection } from './editor-navigation.mjs';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw Error('Use an explicitly disposable instance.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'selection-workspace');
await mkdir(shots, { recursive: true });
const php = process.env.MOCKDECK_PHP_BIN ?? 'php';
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
async function poll(fn, message) {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) { if (await fn()) return; await new Promise(r => setTimeout(r, 80)); }
    throw Error(message);
}
function state(id) {
    return JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $e=App\\Models\\MockEndpoint::findOrFail(${id}); echo json_encode(['states'=>$e->callStates->keyBy('environment_id')->map(fn($s)=>$s->getAttributes()),'responses'=>$e->responses->keyBy('id')->map(fn($r)=>$r->getAttributes()),'revisions'=>App\\Models\\Revision::count(),'active'=>app(App\\Services\\Environments\\EnvironmentContext::class)->active()->id]);`], { env: process.env, encoding: 'utf8' }));
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(php, ['tests/Support/selection-workspace-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const [first, second, fallback] = fixture.responses;
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const client = await request.newContext(); // Independent, cookie-free mock traffic.
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        await page.addInitScript(t => localStorage.setItem('mockdeck-theme', t), theme);
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit?tab=response`);
        const action = async (method, fn) => {
            const pending = page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components?.some(c => c.calls?.some(call => call.method === method)));
            await fn(); await (await pending).finished();
        };
        const row = id => page.locator(`[wire\\:key="response-${id}"]`);
        const settings = page.locator('.selection-settings');
        const saveSelection = async () => {
            await action('saveSelection', () => settings.getByRole('button', { name: 'Save selection', exact: true }).click());
            await poll(async () => (await settings.innerText()).includes('Selection saved.'), 'Selection not saved');
        };
        const capture = async (width, name, locator) => {
            await page.setViewportSize({ width, height: 1000 });
            while (await page.locator('[data-dismiss-toast]').count()) await page.locator('[data-dismiss-toast]').first().click();
            await locator.evaluate(el => el.scrollIntoView({ block: 'start' }));
            await page.evaluate(() => window.scrollTo(0, 0));
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            const paneRight = await page.locator('.endpoint-response-workspace').evaluate(el => el.getBoundingClientRect().left + el.clientWidth);
            const boxes = await page.locator('.response-manager input:visible,.response-manager select:visible,.response-manager textarea:visible,.response-manager button:visible,.response-manager summary:visible,.selection-settings:visible,.selection-response-card:visible,.selection-workspace-heading:visible,.selection-mode-help:visible,.saved-weight-note:visible,.sequence-runtime:visible,.selection-settings .segmented-control:visible').evaluateAll(els => els.map(el => { const b = el.getBoundingClientRect(); return { x: b.x, right: b.right }; }));
            for (const b of boxes) assert.ok(b.x >= -1 && b.right <= Math.min(width, paneRight) + 1, `${theme}/${width}/${name}: control overflows visible pane`);
            await page.screenshot({ path: resolve(shots, `${theme}-${width}-${name}.png`), animations: 'disabled' });
        };
        await page.getByRole('link', { name: /^Selection & pool/ }).click();
        await poll(async () => await page.locator('#response-selection-title').evaluate(el => document.activeElement === el), 'Selection anchor should move keyboard focus');
        assert.ok((await row(first).innerText()).includes('25.0% of saved pool'));
        assert.ok((await row(second).innerText()).includes('50.0% of saved pool'));
        await capture(1440, 'weighted', settings);
        await capture(320, 'weighted', settings);
        await page.setViewportSize({ width: 1440, height: 1000 });
        const before = state(fixture.endpoint);
        await action('setSelectionMode', () => settings.getByRole('button', { name: 'Sequence', exact: true }).click());
        await page.locator('#sequence-on-exhaust').selectOption('repeat_last');
        await saveSelection();
        const sequenceState = state(fixture.endpoint).states;
        assert.deepEqual(sequenceState, before.states, 'Mode save must not advance runtime');
        // Both alternatives operate the same ordered response pool.
        const later = row(first).getByRole('button', { name: `Move response ${first} later`, exact: true });
        assert.equal(await later.isVisible(), true, 'Reorder must not be hidden in Details');
        await later.focus(); await action('moveSelectionResponse', () => page.keyboard.press('Enter'));
        await poll(async () => (await page.locator('.selection-response-card').first().getAttribute('wire:key')) === `response-${second}`, 'Keyboard reorder failed');
        await page.waitForLoadState('networkidle');
        await page.locator('.selection-response-card').first().evaluate(el => el.scrollIntoView({ block: 'start' }));
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const sourceBox = await row(second).locator(':scope > [data-schema-drag-handle]').boundingBox();
        const targetBox = await row(first).locator('.response-row-summary').boundingBox();
        await action('reorderSchemaRow', async () => {
            await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
            await page.mouse.down();
            await page.mouse.move(sourceBox.x + sourceBox.width / 2 + 8, sourceBox.y + sourceBox.height / 2, { steps: 3 });
            const x = targetBox.x + Math.min(30, targetBox.width / 2), y = targetBox.y + targetBox.height / 2;
            await page.mouse.move(x, y, { steps: 12 });
            await page.mouse.move(x + 1, y + 1, { steps: 2 });
            await page.mouse.up();
        });
        await poll(async () => (await page.locator('.selection-response-card').first().getAttribute('wire:key')) === `response-${first}`, 'Pointer drag did not restore the original order');
        await saveSelection();
        for (const expected of [503, 503, 200, 200]) assert.equal((await client.get(base + fixture.path)).status(), expected);
        await page.reload(); await openEditorSection(page, 'Response');
        await poll(async () => (await settings.innerText()).includes('Next response: 3 of 3'), 'Sequence status not updated');
        const runtimeBeforePreview = state(fixture.endpoint).states;
        for (const width of [1440, 1024, 390, 320]) await capture(width, 'sequence', settings);
        assert.deepEqual(state(fixture.endpoint).states, runtimeBeforePreview, 'Preview/capture advanced runtime');
        await settings.getByRole('button', { name: 'Reset sequence', exact: true }).click();
        const dialog = page.locator('#confirm-dialog');
        assert.ok((await dialog.innerText()).includes(fixture.environment));
        await action('resetSequence', () => dialog.locator('[data-confirm-accept]').click());
        const reset = state(fixture.endpoint);
        assert.equal(reset.states[fixture.active].sequence_position, 0);
        assert.equal(reset.states[fixture.active].total_match_count, 4);
        assert.deepEqual(reset.states[fixture.other], before.states[fixture.other]);
        assert.equal(reset.active, fixture.active);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await action('setSelectionMode', () => settings.getByRole('button', { name: 'Rule-based', exact: true }).click());
        await action('saveSelection', () => settings.getByRole('button', { name: 'Save selection', exact: true }).click());
        await poll(async () => (await settings.locator('[role="alert"]').innerText()).includes('exactly one'), 'Missing fallback error');
        await capture(1440, 'rule-error', settings);
        await action('setFallback', () => row(fallback).getByRole('radio', { name: `Use response ${fallback} as default fallback` }).check());
        assert.equal(await row(first).locator('.response-row-details').evaluate(el => el.open), false);
        const conditions = row(first).locator('.selection-response-details > details');
        if (!await conditions.evaluate(el => el.open)) await conditions.locator(':scope > summary').click();
        await action('addRule', () => row(first).getByRole('button', { name: 'Add condition', exact: true }).click());
        const condition = row(first).locator('.rule-condition-row');
        await condition.getByLabel('Field name', { exact: false }).fill('X-Mode');
        await condition.getByRole('combobox', { name: /^Operator/ }).selectOption('exists');
        // Live property updates do not emit a method call; wait for the real rendered disabled state.
        await poll(() => condition.getByLabel('Value', { exact: true }).isDisabled(), 'Exists did not disable Value');
        await condition.getByRole('combobox', { name: /^Operator/ }).selectOption('equals');
        await condition.getByLabel('Value', { exact: true }).fill('yes');
        await saveSelection();
        assert.equal((await client.get(base + fixture.path, { headers: { 'X-Mode': 'yes' } })).status(), 503);
        assert.equal((await client.get(base + fixture.path, { headers: { 'X-Mode': 'no' } })).status(), 200);
        for (const width of [1440, 1024, 390, 320]) await capture(width, 'rules', row(first));
        await page.setViewportSize({ width: 1440, height: 1000 });
        await row(fallback).locator('.overflow-menu > summary').click();
        await row(fallback).getByRole('button', { name: 'Delete', exact: true }).click();
        await action('delete', () => dialog.locator('[data-confirm-accept]').click());
        await poll(async () => (await settings.innerText()).includes('Deleting the fallback would leave rule mode invalid'), 'Fallback deletion not protected');
        assert.equal(await row(fallback).count(), 1);
        await action('edit', () => row(fallback).getByRole('button', { name: 'Edit', exact: true }).click());
        await page.waitForLoadState('networkidle');
        const fault = page.locator('.response-fault-settings');
        if (!await fault.evaluate(el => el.open)) await fault.locator(':scope > summary').click();
        await fault.getByLabel('Enable fault injection', { exact: true }).check();
        await page.waitForLoadState('networkidle');
        if (!await fault.evaluate(el => el.open)) await fault.locator(':scope > summary').click();
        assert.notEqual(await fault.locator('.fault-summary').evaluate(el => getComputedStyle(el).display), 'grid', 'Fault annotation must not inherit disclosure title grid');
        const probabilityBefore = state(fixture.endpoint).responses;
        await page.locator('#response-fault-probability').fill('41');
        await poll(async () => (await fault.locator('.fault-summary').textContent()).replace(/\s+/g, '').includes('41%'), 'Draft probability summary did not update');
        assert.deepEqual(state(fixture.endpoint).responses, probabilityBefore, 'Typing a probability must not save');
        await page.locator('#response-fault-probability').fill('');
        await poll(async () => (await fault.locator('.fault-summary').textContent()).replace(/\s+/g, '').includes('—%'), 'Blank probability must show an incomplete draft');
        assert.deepEqual(state(fixture.endpoint).responses, probabilityBefore);
        await page.locator('#response-fault-probability').fill('100');
        await poll(async () => (await fault.locator('.fault-summary').textContent()).replace(/\s+/g, '').includes('100%'), 'Probability not restored');
        await page.locator('#response-fault-delay-min').fill('90');
        await page.locator('#response-fault-delay-max').fill('50');
        const invalidBefore = state(fixture.endpoint).responses;
        await action('save', () => page.getByRole('button', { name: 'Update response', exact: true }).click());
        await page.locator('[data-error-for="faultDelayMsMax"]').waitFor();
        assert.deepEqual(state(fixture.endpoint).responses, invalidBefore);
        await capture(320, 'fault-error', page.locator('[data-error-for="faultDelayMsMax"]'));
        await page.locator('#response-fault-delay-max').fill('');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await action('save', () => page.getByRole('button', { name: 'Update response', exact: true }).click());
        const start = performance.now();
        assert.equal((await client.get(base + fixture.path)).status(), 200);
        assert.ok(performance.now() - start >= 75, '90ms delay was not observable');
        for (const [type, expected] of [['malformed_body', '{"mockdeck_fault":'], ['truncated_body', '{"ste'], ['timeout', '{"step":3}']]) {
            await action('edit', () => row(fallback).getByRole('button', { name: 'Edit', exact: true }).click());
            if (!await fault.evaluate(el => el.open)) await fault.locator(':scope > summary').click();
            await page.locator('#response-fault-type').selectOption(type);
            const expectedLabel = { malformed_body: 'Malformed body', truncated_body: 'Truncated body', timeout: 'Long delay' }[type];
            await poll(async () => (await fault.locator('.fault-summary').innerText()).includes(expectedLabel), 'Fault type summary did not settle');
            if (type === 'malformed_body') {
                await poll(async () => (await fault.innerText()).includes('unfinished document') && await page.locator('#response-fault-delay-min').count() === 0, 'Malformed-body fields did not settle');
            } else if (type === 'truncated_body') {
                await poll(async () => (await fault.innerText()).includes('first half') && await page.locator('#response-fault-delay-min').count() === 0, 'Truncated-body fields did not settle');
            }
            if (type === 'timeout') {
                await poll(async () => (await fault.innerText()).includes('Approximation'), 'Timeout must disclose approximation');
                await page.locator('#response-fault-delay-min').fill('90');
            }
            for (const width of [1440, 320]) await capture(width, `fault-${type}`, fault);
            await action('save', () => page.getByRole('button', { name: 'Update response', exact: true }).click());
            assert.equal(await (await client.get(base + fixture.path)).text(), expected);
        }
        const final = state(fixture.endpoint);
        assert.equal(final.states[fixture.active].total_match_count, 10, 'Faulted calls must still count');
        assert.deepEqual(final.states[fixture.other], before.states[fixture.other]);
        assert.deepEqual(errors, []);
        console.log(`PASS ${theme}: saved weight chances; 503/503/200 sequence + preview/reset; keyboard/drag order; rule/fallback and protected delete; invalid and four real faults; isolated counters; four-width geometry`);
        await client.dispose(); await page.close();
    }
} finally { await browser.close(); }
