// Real app, disposable DB only. Preview and view switching must not persist drafts.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { openEditorSection } from './editor-navigation.mjs';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw Error('Use an explicitly disposable instance.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'response-authoring');
await mkdir(shots, { recursive: true });
const php = process.env.MOCKDECK_PHP_BIN ?? 'php';
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
async function poll(fn, message) {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) { if (await fn()) return; await new Promise(r => setTimeout(r, 80)); }
    throw Error(message);
}
function snapshot(id) {
    return execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $e=App\\Models\\MockEndpoint::findOrFail(${id}); echo json_encode([$e->getAttributes(),$e->responses->map(fn($r)=>$r->getAttributes()),App\\Models\\Revision::count(),App\\Models\\EndpointCallState::count()]);`], { env: process.env, encoding: 'utf8' });
}
try {
    for (const theme of ['light', 'dark']) {
        const fixture = JSON.parse(execFileSync(php, ['tests/Support/endpoint-editor-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        page.on('dialog', d => d.type() === 'beforeunload' ? d.accept() : d.dismiss());
        await page.addInitScript(t => localStorage.setItem('mockdeck-theme', t), theme);
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit?tab=response`);
        await openEditorSection(page, 'Response');
        await page.getByLabel('Response to edit', { exact: true }).selectOption(String(fixture.responses[0]));
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.responses[0]}`), 'Response not selected');
        const before = snapshot(fixture.endpoint);
        const action = async (method, fn) => {
            const pending = page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components?.some(c => c.calls?.some(call => call.method === method)));
            await fn(); await (await pending).finished();
        };
        const setTemplate = async source => {
            await page.locator('#response-template').fill(source);
            await poll(async () => JSON.parse(await page.locator('.response-manager').getAttribute('data-draft-current')).template === source, 'Template draft did not reach server');
        };
        const render = async () => {
            await action('previewTemplate', () => page.getByRole('button', { name: 'Regenerate', exact: true }).click());
            await page.locator('.template-preview pre').waitFor();
            return page.locator('.template-preview pre').innerText();
        };
        const capture = async (width, state, target) => {
            if (page.viewportSize().width !== width) await page.setViewportSize({ width, height: 1000 });
            if (target) await page.locator(target).evaluate(el => el.scrollIntoView({ block: 'start' }));
            await page.evaluate(() => window.scrollTo(0, 0));
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${state}/${width}: document overflow`);
            const bounds = await page.locator('.response-form input:visible, .response-form select:visible, .response-form textarea:visible, .response-form button:visible').evaluateAll(els => els.map(el => { const b = el.getBoundingClientRect(); return { x: b.x, right: b.right }; }));
            for (const b of bounds) assert.ok(b.x >= -1 && b.right <= width + 1, `${state}/${width}: control escapes viewport`);
            await page.screenshot({ path: resolve(shots, `${theme}-${width}-${state}.png`), animations: 'disabled' });
        };
        const literal = 'raw {{literal_token}}\nnot JSON <unchanged>';
        await page.locator('#response-body').fill(literal);
        await capture(1440, 'static', '.response-draft-picker');
        await action('setBodyMode', () => page.getByRole('button', { name: 'Template', exact: true }).click());
        await action('setEditorView', () => page.getByRole('button', { name: 'JSON', exact: true }).click());
        await setTemplate('{"email":"$internet.email","name":"$person.firstName"}');
        await page.locator('#template-locale').selectOption('fr_FR');
        await page.locator('#template-seed-mode').selectOption('fixed');
        await page.locator('#template-seed').fill('42');
        await poll(async () => JSON.parse(await page.locator('.response-manager').getAttribute('data-draft-current')).seed === 42, 'Fixed seed not synchronized');
        const sample = await render();
        assert.match(JSON.parse(sample).email, /^[^@\s]+@[^@\s]+\.[^@\s]+$/);
        assert.equal(await render(), sample, 'Fixed seed should reproduce its sample');
        assert.ok((await page.locator('#response-preview-notice').innerText()).includes('sample'));
        assert.ok((await page.locator('#template-preview-title').innerText()).includes('Sample data'));
        for (const width of [1440, 320]) await capture(width, 'template-controls', '.response-body-workspace');
        for (const width of [1440, 1024, 390, 320]) await capture(width, 'json-preview', '.template-preview');
        await page.locator('#template-seed-mode').selectOption('random');
        await page.locator('#template-seed').waitFor({ state: 'detached' });
        assert.ok((await page.locator('.template-seed-note').innerText()).includes('fresh values'));
        await render();
        await page.locator('#template-seed-mode').selectOption('request');
        await poll(async () => (await page.locator('.template-seed-note').innerText()).includes('same request signature'), 'Request signature seed help missing');
        assert.equal(await render(), await render(), 'Signature seed should reproduce its sample');
        await setTemplate('{"alias":"$internet.userName","bad":"$person.notARealMethod"}');
        await poll(async () => (await page.locator('.template-validation').innerText()).includes('UNKNOWN_METHOD'), 'Unknown-method issue missing');
        assert.ok((await page.locator('.template-validation').innerText()).includes('RENAMED_METHOD'));
        assert.equal(await page.getByRole('button', { name: 'Update response', exact: true }).isDisabled(), true);
        await capture(1440, 'issues', '.template-validation');
        await capture(320, 'issues', '.template-validation');
        await setTemplate('{"method":"{{$request.method}}"}');
        await poll(() => page.getByRole('button', { name: 'Builder', exact: true }).isDisabled(), 'Context template must stay JSON-only');
        assert.equal(JSON.parse(await render()).method, 'POST', 'Preview uses the shared synthetic request sample');
        await setTemplate('{"profile":{"first":"alpha","second":"beta"},"items":{"$repeat":2,"$item":1}}');
        await poll(() => page.getByRole('button', { name: 'Builder', exact: true }).isEnabled(), 'Representable template did not enable Builder');
        await action('setEditorView', () => page.getByRole('button', { name: 'Builder', exact: true }).click());
        const move = page.getByRole('button', { name: 'Move field up for Response field fields.0.children.1 (second)', exact: true });
        await move.focus();
        await action('moveSchemaRow', () => page.keyboard.press('Enter'));
        assert.equal(await page.locator('.schema-nested .schema-key input').first().inputValue(), 'second');
        await page.setViewportSize({ width: 1440, height: 1000 });
        const children = page.locator('.response-form [data-schema-parent="fields.0.children"]');
        await children.nth(0).scrollIntoViewIfNeeded();
        await action('reorderSchemaRow', () => children.nth(0).locator(':scope > .schema-row-main > [data-schema-drag-handle]').dragTo(children.nth(1).locator(':scope > .schema-row-main > .schema-key')));
        assert.equal(await page.locator('.schema-nested .schema-key input').first().inputValue(), 'first', 'Pointer drag should reorder the same nested fields');
        await render();
        for (const width of [1440, 1024, 390, 320]) await capture(width, 'builder', '.schema-builder');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await action('setEditorView', () => page.getByRole('button', { name: 'JSON', exact: true }).click());
        await setTemplate('{"email":"$internet.email"}');
        await action('setEditorView', () => page.getByRole('button', { name: 'Builder', exact: true }).click());
        const picker = page.locator('.response-form .faker-picker').first();
        await picker.locator(':scope > summary').click();
        await picker.getByRole('searchbox').fill('number.int');
        await picker.getByRole('option').filter({ has: page.locator('code', { hasText: /^number.int$/ }) }).click();
        await poll(async () => (await picker.locator(':scope > summary').innerText()).includes('number.int'), 'Catalog choice not applied');
        await page.locator('.response-form .schema-args > summary').click();
        const args = page.locator('.response-form .schema-args');
        await args.getByLabel('Min', { exact: true }).fill('9');
        await args.getByLabel('Max', { exact: true }).fill('9');
        await poll(async () => (await page.locator('.response-manager').getAttribute('data-draft-current')).includes('number.int'), 'Faker draft missing');
        await render();
        assert.equal(JSON.parse(await page.locator('.template-preview pre').innerText()).email, 9, 'Catalog arguments must reach the real renderer');
        await args.evaluate(el => { el.open = false; });
        await page.setViewportSize({ width: 320, height: 1000 });
        await picker.locator(':scope > summary').scrollIntoViewIfNeeded();
        if (!await picker.evaluate(el => el.open)) await picker.locator(':scope > summary').click();
        await picker.getByRole('searchbox').fill('');
        await picker.locator('.faker-picker-panel').waitFor({ state: 'visible' });
        // Visibility can precede animation-frame positioning of the shared panel.
        await poll(async () => {
            const box = await picker.locator('.faker-picker-panel').boundingBox();
            return box && box.x >= 0 && box.x + box.width <= 321;
        }, 'Catalog popover escapes viewport after positioning');
        const pop = await picker.locator('.faker-picker-panel').boundingBox();
        assert.ok(pop && pop.x >= 0 && pop.x + pop.width <= 321, `Catalog popover escapes viewport: ${JSON.stringify(pop)}`);
        await capture(320, 'catalog-open', null);
        await page.keyboard.press('Escape');
        await action('setBodyMode', () => page.getByRole('button', { name: 'Static', exact: true }).click());
        assert.equal(await page.locator('#response-body').inputValue(), literal, 'Static body bytes changed during template editing');
        assert.equal(snapshot(fixture.endpoint), before, 'Preview/view changes persisted configuration, revisions or call state');
        assert.deepEqual(errors, []);
        console.log(`PASS ${theme}: raw Static retention, fixed/random/signature samples, issues, JSON-only context, nested reorder, catalog arguments, no persistence; 1440/1024/390/320 geometry`);
        await page.close();
    }
} finally { await browser.close(); }
