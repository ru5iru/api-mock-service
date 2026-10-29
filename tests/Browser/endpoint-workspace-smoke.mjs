// Requires a disposable instance. Creates fixtures; never delivers a callback.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { openEditorSection } from './editor-navigation.mjs';
const require = createRequire(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? `${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json` : import.meta.url);
const { chromium, request } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'endpoint-workspace');
await mkdir(shots, { recursive: true });
async function poll(check, message) {
    const until = Date.now() + 15000;
    while (Date.now() < until) { if (await check()) return; await new Promise(r => setTimeout(r, 80)); }
    throw new Error(message);
}
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
const errors = [];
try {
    for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] });
        const page = await context.newPage();
        page.on('pageerror', e => errors.push(`${theme}: ${e.message}`));
        await page.addInitScript(value => localStorage.setItem('mockdeck-theme', value), theme);
        const invoke = await request.newContext(); // No dashboard-session Cookie header.
        await page.goto(`${base}/dashboard/endpoints/create`);
        await page.getByRole('heading', { name: 'Create the endpoint first', exact: true }).waitFor();
        assert.equal(await page.locator('#panel-request').isVisible(), true);
        const path = `/workspace-create-${theme}-${Date.now()}`;
        await page.locator('#raw-curl').fill(`curl 'https://source.example.test${path}'`);
        await poll(async () => (await page.locator('#curl-status').innerText()).includes('Parsed:'), 'Request not parsed');
        await openEditorSection(page, 'Matching');
        await page.getByRole('checkbox', { name: /^Ignore all headers/ }).check();
        await openEditorSection(page, 'Settings');
        await page.locator('#endpoint-priority').fill('12');
        await page.locator('#save-actions button[type=submit]').click();
        await page.waitForURL(/\/endpoints\/\d+\/edit\?tab=response/);
        const createdUrl = page.url();
        await page.locator('#response-empty-note').waitFor();
        assert.equal((await invoke.get(base + path)).status(), 500, 'Saved request without a response must retain diagnostic 500');
        await page.locator('#response-body').fill('literal response text');
        await page.locator('#status-code').fill('201');
        await page.locator('#response-headers').fill('{"Content-Type":"text/plain"}');
        await page.locator('.response-form').getByRole('button', { name: 'Add response', exact: true }).click();
        await poll(async () => await page.locator('.selection-response-card').count() === 1, 'First response not saved');
        await page.locator('#response-empty-note').waitFor({ state: 'hidden' });
        const called = await invoke.get(base + path);
        assert.equal(called.status(), 201); assert.equal(await called.text(), 'literal response text');
        await page.locator('.endpoint-invocation-actions').getByRole('button', { name: 'Copy mock curl', exact: true }).click();
        await poll(async () => (await page.evaluate(() => navigator.clipboard.readText())).includes(base + path), 'Saved mock cURL not copied');
        assert.equal(await page.locator('#endpoint-settings .response-form').count(), 0, 'Response form must not be nested in request form');
        await invoke.dispose();

        const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP_BIN ?? 'php', ['tests/Support/endpoint-editor-fixture.php'], { env: process.env, encoding: 'utf8' }));
        const url = `${base}/dashboard/endpoints/${fixture.endpoint}/edit`;
        for (const [legacy, workspace] of [['request', 'Editor'], ['matching', 'Editor'], ['response', 'Editor'], ['callback', 'Callback'], ['settings', 'Settings'], ['editor', 'Editor']]) {
            await page.goto(`${url}?tab=${legacy}`);
            await poll(async () => (await page.locator('[data-endpoint-tabs]').getByRole('tab', { name: new RegExp(`^${workspace}`) }).getAttribute('aria-selected')) === 'true', `Deep link ${legacy} did not map to ${workspace}`);
            assert.equal(await page.locator('.response-manager').count(), 1);
            if (legacy === 'matching') assert.equal(await page.locator('#panel-matching').isVisible(), true);
            if (legacy === 'response') assert.equal(await page.locator('#panel-response').isVisible(), true);
        }
        await page.goto(url);
        const managerId = await page.locator('.response-manager').getAttribute('wire:id');
        const picker = page.getByLabel('Response to edit', { exact: true });
        await picker.selectOption(String(fixture.responses[0]));
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.responses[0]}`), 'First response not loaded');
        await page.locator('#response-body').fill('draft A');
        await picker.selectOption(String(fixture.responses[1]));
        await poll(async () => (await page.locator('.response-form h3').innerText()).includes(`#${fixture.responses[1]}`), 'Second response not loaded');
        await page.locator('#response-body').fill('draft B');
        await picker.selectOption(String(fixture.responses[0]));
        await poll(async () => (await page.locator('#response-body').inputValue()) === 'draft A', 'Picker discarded response A draft');
        await openEditorSection(page, 'Settings'); await page.locator('#endpoint-priority').fill('42');
        await page.getByRole('textbox', { name: 'New collection name', exact: true }).fill(`Workspace ${theme}`);
        await page.locator('.organization-grid > .field').first().getByRole('button', { name: 'Create', exact: true }).click();
        await poll(async () => (await page.locator('#endpoint-collection option:checked').innerText()) === `Workspace ${theme}`, 'Inline collection draft not selected');
        await page.getByRole('textbox', { name: 'New tag name', exact: true }).fill(`workspace-${theme}`);
        await page.getByRole('button', { name: 'Add tag', exact: true }).click();
        await poll(async () => await page.locator('.tag-input input:checked').count() === 1, 'Inline tag draft not selected');
        await openEditorSection(page, 'Callback');
        const callbackLoaded = page.waitForResponse(r => r.url().includes('/update') && r.request().method() === 'POST' && r.request().postDataJSON().components?.some(c => c.calls?.some(call => call.method === 'editCallback')));
        await page.locator('.callback-response-row').first().getByRole('button').click();
        await (await callbackLoaded).finished();
        await page.locator('#callback-url').fill('https://receiver.example.test/unsaved');
        await openEditorSection(page, 'Response');
        assert.equal(await page.locator('#response-body').inputValue(), 'draft A');
        assert.equal(await page.locator('.response-manager').getAttribute('wire:id'), managerId, 'Task navigation remounted response drafts');
        await page.getByRole('button', { name: 'New response', exact: true }).click();
        await poll(async () => await picker.inputValue() === '', 'Picker did not reset for new response draft');
        await picker.selectOption(String(fixture.responses[1]));
        await poll(async () => (await page.locator('#response-body').inputValue()) === 'draft B', 'New response action discarded response B');
        await openEditorSection(page, 'Settings'); assert.equal(await page.locator('#endpoint-priority').inputValue(), '42');
        await openEditorSection(page, 'Callback');
        assert.ok((await page.locator('.callback-form h3').innerText()).includes(`#${fixture.responses[1]}`), 'Callback must identify the selected response');
        await page.locator('.callback-response-row').first().getByRole('button').click();
        await poll(async () => (await page.locator('#callback-url').inputValue()) === 'https://receiver.example.test/unsaved', 'Response A callback draft was not restored');
        // Reject a response draft through global save; keep all drafts and open the Editor destination.
        await openEditorSection(page, 'Response');
        assert.equal(await page.locator('#response-body').inputValue(), 'draft A', 'Callback selection must remain bound to response A');
        await picker.selectOption(String(fixture.responses[1]));
        await poll(async () => (await page.locator('#response-body').inputValue()) === 'draft B', 'Response B draft must still be available');
        await page.locator('#response-weight').fill('0');
        await page.locator('#save-actions button[type=submit]').click();
        await page.locator('[data-error-for="weight"]').waitFor();
        assert.equal(await page.locator('[data-endpoint-tabs]').getByRole('tab', { name: /^Editor/ }).getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#response-body').inputValue(), 'draft B');
        await page.screenshot({ path: resolve(shots, `${theme}-1440-validation.png`) });
        await page.locator('#response-weight').fill('1');
        const savedEditorId = await page.locator('.endpoint-editor').getAttribute('wire:id');
        await page.locator('#save-actions button[type=submit]').click();
        await poll(async () => (await page.locator('.endpoint-editor').getAttribute('wire:id')) !== savedEditorId, 'Corrected global save did not finish');
        await page.reload();
        const saved = JSON.parse(execFileSync(process.env.MOCKDECK_PHP_BIN ?? 'php', ['-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $e = App\\Models\\MockEndpoint::findOrFail(${fixture.endpoint}); echo json_encode(['priority'=>$e->priority,'collection'=>$e->collection->name,'tags'=>$e->tags->pluck('name'),'responses'=>$e->responses->mapWithKeys(fn($r)=>[$r->id=>['body'=>$r->body,'callback_url'=>$r->callback_url]])]);`], { env: process.env, encoding: 'utf8' }));
        assert.equal(saved.priority, 42); assert.equal(saved.collection, `Workspace ${theme}`); assert.ok(saved.tags.includes(`workspace-${theme}`));
        assert.equal(saved.responses[fixture.responses[0]].body, 'draft A'); assert.equal(saved.responses[fixture.responses[1]].body, 'draft B');
        assert.equal(saved.responses[fixture.responses[0]].callback_url, 'https://receiver.example.test/unsaved');
        // Keyboard switching uses the shared roving-tab behavior.
        await page.locator('#tab-editor').focus(); await page.keyboard.press('End');
        await poll(async () => await page.locator('#tab-settings').getAttribute('aria-selected') === 'true', 'Workspace End key did not select Settings');
        await page.keyboard.press('Home');
        await poll(async () => await page.locator('#tab-editor').getAttribute('aria-selected') === 'true', 'Workspace Home key did not select Editor');
        await page.locator('#tab-request').waitFor({ state: 'visible' });
        await page.locator('#tab-request').focus();
        assert.equal(await page.locator('#tab-request').evaluate(el => el === document.activeElement), true, 'Request subtab must own keyboard focus');
        await page.keyboard.press('ArrowRight');
        await poll(async () => await page.locator('#tab-matching').getAttribute('aria-selected') === 'true', 'Matching subtab keyboard navigation failed');

        for (const width of [1440, 1024, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const section of ['Request', 'Matching', 'Settings', 'Callback']) {
                await openEditorSection(page, section);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${theme}/${width}/${section}: page overflows`);
                const viewport = await page.locator('[data-editor-viewport]').boundingBox();
                const actions = await page.locator('#save-actions').boundingBox();
                assert.ok(viewport.y + viewport.height <= actions.y - 15, `${theme}/${width}: save bar obscures pane`);
                const visible = await page.locator('.endpoint-authoring-grid input:visible, .endpoint-authoring-grid select:visible, .endpoint-authoring-grid textarea:visible').evaluateAll(els => els.map(el => { const b = el.getBoundingClientRect(); return { x: b.x, right: b.right }; }));
                for (const b of visible) assert.ok(b.x >= 0 && b.right <= width + 1, `${theme}/${width}/${section}: control escapes viewport`);
                if (width === 1440 && section === 'Request') {
                    const left = await page.locator('.endpoint-request-workspace').boundingBox();
                    const right = await page.locator('.endpoint-response-workspace').boundingBox();
                    assert.ok(right.x >= left.x + left.width - 1 && Math.abs(right.y - left.y) <= 1, 'Request/response panes must sit side by side');
                    assert.equal(await page.locator('.endpoint-request-workspace').evaluate(el => getComputedStyle(el).overflowY), 'auto');
                }
                await page.locator('[data-editor-viewport]').evaluate(el => { el.scrollTop = 0; });
                await page.locator('.endpoint-request-workspace').evaluate(el => { el.scrollTop = 0; });
                await page.locator('.endpoint-response-workspace').evaluate(el => { el.scrollTop = 0; });
                await page.screenshot({ path: resolve(shots, `${theme}-${width}-${section.toLowerCase()}.png`) });
            }
        }
        // Cancel navigation after editing either pane; do not discard silently.
        await page.setViewportSize({ width: 1440, height: 1000 });
        await openEditorSection(page, 'Settings');
        await page.locator('#endpoint-priority').fill('43');
        await page.locator('.workbench-rail a[href$="/logs"]').click();
        await page.locator('#confirm-dialog').waitFor({ state: 'visible' });
        await page.locator('#confirm-dialog [data-confirm-cancel]').click();
        assert.equal(new URL(page.url()).pathname, new URL(url).pathname);
        await context.close();
        console.log(`${theme}: create/no-response500/static201, legacy URLs, one mounted manager, picker/task draft retention, validation and keyboard pass; pane/control geometry at1440/1024/390/320px`);
    }
    assert.deepEqual(errors, []);
} finally { await browser.close(); }
