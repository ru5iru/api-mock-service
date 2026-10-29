import { openEditorSection } from './editor-navigation.mjs';
// This suite writes fixtures: run only against an explicitly disposable database.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
const require = createRequire(import.meta.url);
const { chromium } = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`)('playwright') : require('playwright');
if (process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw Error('Set MOCKDECK_BROWSER_FIXTURE=1 only with a disposable database.');
const base = process.env.MOCKDECK_BASE_URL;
if (!base) throw Error('Set MOCKDECK_BASE_URL.');
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP_BIN || 'php', ['tests/Support/critique-browser-fixture.php'], { encoding: 'utf8', env: process.env }));
const directory = process.env.MOCKDECK_SCREENSHOT_DIR || 'tests/Browser/screenshots/critique';
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.MOCKDECK_CHROMIUM_PATH ? { executablePath: process.env.MOCKDECK_CHROMIUM_PATH } : {}) });
try {
    for (const theme of ['light', 'dark']) for (const width of [390, 740, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        // The test deliberately replaces pages after checking drafts; accept only the unload guard.
        page.on('dialog', dialog => dialog.type() === 'beforeunload' ? dialog.accept() : dialog.dismiss());
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        await page.goto(`${base}/dashboard/environments`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        await page.locator(`.environment-list-items button[wire\\:click="selectEnvironment(${fixture.environment})"]`).click();
        await page.locator('.environment-editor .card').first().waitFor();
        await page.waitForTimeout(350);
        const containment = await page.locator('.environment-editor').evaluate(editor => {
            const card = editor.firstElementChild;
            const wrapper = editor.querySelector('.table-scroll');
            wrapper.scrollLeft = 10000;
            return { right: card.getBoundingClientRect().right, viewport: document.documentElement.clientWidth,
                scrollable: wrapper.scrollLeft > 0,
                tableActions: [...wrapper.querySelectorAll('button')].map(b => ({ left: b.getBoundingClientRect().left, right: b.getBoundingClientRect().right })),
                scrollLeft: wrapper.getBoundingClientRect().left, scrollRight: wrapper.getBoundingClientRect().right,
                actions: [...card.querySelectorAll('button')].filter(b => b.getClientRects().length).map(b => b.getBoundingClientRect().right) };
        });
        assert.ok(containment.right <= containment.viewport);
        for (const right of containment.actions) assert.ok(right <= containment.viewport);
        if (width === 390) assert.ok(containment.scrollable, 'Variable actions require reachable internal scrolling');
        for (const action of containment.tableActions) {
            assert.ok(action.left >= containment.scrollLeft - 1 && action.right <= containment.scrollRight + 1, 'Every table action is reachable at the end of internal scroll');
        }
        const key = page.locator(`input[wire\\:model="variableDrafts.${fixture.variable}.key"]`);
        await key.fill('1bad');
        await page.locator(`button[wire\\:click="saveVariable(${fixture.variable})"]`).click();
        await page.waitForFunction(() => document.querySelector('input[aria-invalid="true"]'));
        assert.equal(await key.getAttribute('aria-invalid'), 'true');
        assert.ok(await key.getAttribute('aria-describedby'));
        assert.equal(await key.evaluate(n => n === document.activeElement), true);
        const name = page.locator('input[wire\\:model="renameEnvironment"]');
        await name.fill('Unsaved regression name');
        await page.locator(`button[wire\\:click="selectEnvironment(${fixture.other})"]`).click();
        await page.locator(`button[wire\\:click="selectEnvironment(${fixture.environment})"]`).click();
        await page.waitForFunction(() => document.querySelector('input[wire\\:model="renameEnvironment"]')?.value === 'Unsaved regression name');
        assert.equal(await key.inputValue(), '1bad');
        await page.screenshot({ path: `${directory}/${theme}-${width}-environment.png`, fullPage: true });

        await page.goto(`${base}/dashboard`);
        // Filter by name so pagination never hides the fixture.
        await page.getByPlaceholder('Search name, method, path, or curl…').fill('Critique regression endpoint');
        const copy = page.locator(`[data-copy-endpoint="${fixture.endpoint}"]`);
        await copy.waitFor(); await copy.focus(); await copy.press('Enter');
        await page.waitForTimeout(350);
        assert.equal(new URL(page.url()).pathname, '/dashboard');
        await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
        const history = page.getByRole('button', { name: 'Endpoint history', exact: true });
        await history.click(); await page.waitForTimeout(350);
        const dialog = page.locator('.history-dialog');
        if (width === 1440) assert.ok((await dialog.boundingBox()).width >= 850);
        for (const column of await dialog.locator('.revision-copy').all()) assert.ok((await column.boundingBox()).width >= 180);
        await page.screenshot({ path: `${directory}/${theme}-${width}-history.png`, fullPage: true });
        if (width === 1440) {
            await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
            const zoomed = await dialog.boundingBox();
            assert.ok(zoomed.x >= 0 && zoomed.x + zoomed.width <= width, 'History contains itself at 200% CSS zoom');
            await page.evaluate(() => { document.documentElement.style.zoom = ''; });
        }
        await page.keyboard.press('Escape');
        assert.equal(await history.evaluate(n => n === document.activeElement), true);

        const tip = page.locator('.help-tip[aria-label="Priority"]');
        await tip.focus(); await page.keyboard.press('Escape'); await page.waitForTimeout(200);
        assert.equal(await tip.locator('.help-tip-content').isVisible(), false);
        await openEditorSection(page, 'Response');
        assert.ok((await page.locator('#panel-response').innerText()).includes('Distinct saved example'));
        assert.ok((await page.locator('.selection-settings').innerText()).includes('Selection saved.'));
        await page.getByRole('button', { name: 'Sequence', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('.selection-settings')?.textContent.includes('Unsaved selection changes.'));
        await page.getByRole('button', { name: 'Template', exact: true }).click();
        await page.getByRole('button', { name: 'JSON', exact: true }).click();
        const editor = page.locator('#response-template');
        await editor.fill('{"value":"$internet.em');
        await page.locator('[data-template-autocomplete]:not([hidden])').waitFor();
        await page.waitForTimeout(650); // allow the Livewire validation morph
        assert.equal(await editor.getAttribute('aria-expanded'), 'true');
        await editor.press('ArrowDown');
        const active = await editor.getAttribute('aria-activedescendant');
        assert.ok(active);
        assert.equal(await page.locator(`[id="${active}"]`).getAttribute('aria-selected'), 'true');
        assert.equal(await editor.evaluate(n => n === document.activeElement), true);
        await editor.press('Escape');
        assert.equal(await editor.getAttribute('aria-activedescendant'), null);
        await page.goto(`${base}/dashboard/endpoints/create`);
        assert.equal(await page.locator('#raw-curl').isVisible(), true);
        assert.equal(await page.locator('#endpoint-priority').isVisible(), false, 'Optional metadata belongs under Settings');
        await openEditorSection(page, 'Settings');
        assert.equal(await page.locator('#endpoint-priority').isVisible(), true);
        assert.equal(await page.locator('#raw-curl').isVisible(), false);

        await page.goto(`${base}/dashboard/logs?type=callbacks&time_range=all&request_log_id=critique-regression-request`);
        assert.ok((await page.locator('.callback-log thead th').first().innerText()).toLowerCase().includes('status'));
        const attempt = page.locator(`tr[wire\\:key="callback-attempt-${fixture.attempt}"]`);
        await attempt.locator('summary').click();
        await page.waitForTimeout(350);
        const evidence = await attempt.innerText();
        assert.ok(evidence.includes('recorded.example.test')); assert.ok(evidence.includes('Receiver unavailable'));
        assert.ok(!evidence.includes('current.example.test')); assert.ok(!evidence.includes('hidden-error-secret'));
        assert.ok(!evidence.includes('hidden-regression-secret'));
        await page.screenshot({ path: `${directory}/${theme}-${width}-callbacks.png`, fullPage: true });
        await page.getByRole('button', { name: 'Remove request scope' }).click();
        await page.getByRole('button', { name: 'Remove request scope' }).waitFor({ state: 'detached' });

        await page.goto(`${base}/dashboard/docs`);
        const search = page.locator('[data-docs-search]');
        await search.fill('certificate');
        assert.ok(await page.locator('.docs-grid > section:visible').count() > 0);
        assert.ok(await page.locator('.docs-grid > section:visible').count() < 25);
        await search.fill('no-match-regression-query');
        assert.equal(await page.locator('.docs-grid > section:visible').count(), 0);
        await page.locator('[data-docs-clear]').click();
        await page.locator('[data-docs-jump]').selectOption('request-matching');
        const heading = await page.locator('#request-matching').boundingBox();
        const bar = await page.locator('.docs-searchbar').boundingBox();
        assert.ok(heading.y >= bar.y + bar.height - 2, 'Sticky navigation must not obscure section headings');
        await page.screenshot({ path: `${directory}/${theme}-${width}-docs.png`, fullPage: false });
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`${theme} ${width}: Environment, History, keyboard, identity, autocomplete, callbacks and docs passed`);
    }
    // Both preview formats exercise the same prospective-change composition.
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    for (const format of ['native', 'postman']) for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${base}/dashboard/config`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        await page.reload();
        if (format === 'postman') await page.locator('[wire\\:click="selectFormat(\'postman\')"]').click();
        await page.locator('input[type=file][wire\\:model="configFile"]').setInputFiles({ name: `${format}.json`, mimeType: 'application/json', buffer: Buffer.from(fixture[format]) });
        await page.locator('input[type=radio][value="upsert"]').check();
        await page.getByRole('button', { name: 'Preview import', exact: true }).click();
        const changes = page.locator('.import-items summary').filter({ hasText: 'Review changes' }).first();
        await changes.waitFor(); await changes.click();
        assert.ok((await page.locator('.import-items').innerText()).includes(format === 'native' ? 'Prospective import name' : 'after'));
        await page.locator('.import-preview').screenshot({ path: `${directory}/${theme}-${width}-${format}-changes.png` });
        console.log(`${theme} ${width}: ${format} changes preview passed`);
    }
    await page.close();
} finally { await browser.close(); }
