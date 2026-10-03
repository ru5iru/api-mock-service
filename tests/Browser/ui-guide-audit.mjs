// Full dashboard/standalone UI contract sweep. Use an isolated database only.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES ? createRequire(`${process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES}/package.json`) : createRequire(import.meta.url);
const { chromium } = require('playwright');
const base = process.env.MOCKDECK_BASE_URL;
if (!base || process.env.MOCKDECK_BROWSER_FIXTURE !== '1') throw new Error('Use a dedicated fixture database.');
const shots = resolve(process.env.MOCKDECK_SCREENSHOT_DIR ?? 'tests/Browser/screenshots', 'ui-audit');
await mkdir(shots, { recursive: true });
const fixture = JSON.parse(execFileSync(process.env.MOCKDECK_PHP ?? 'php', ['tests/Support/ui-audit-fixture.php'], { env: process.env, encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
const errors = [], results = [];
async function inspect(page, label) {
    // A Livewire morph can remove JS-authored attributes from an unchanged summary.
    await page.evaluate(() => document.querySelectorAll('details[data-disclosure] > summary, details[data-menu] > summary').forEach(el => el.removeAttribute('aria-expanded')));
    await page.waitForTimeout(150);
    const result = await page.evaluate(() => {
        const failures = [], counts = {};
        const visible = el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
        const check = (ok, el, message) => { if (!ok) failures.push(`${message}: ${el.outerHTML.slice(0, 170)}`); };
        const name = el => el.getAttribute('aria-label') || (el.getAttribute('aria-labelledby') || '').split(' ').map(id => document.getElementById(id)?.textContent || '').join(' ').trim() || [...(el.labels || [])].map(label => { const copy = label.cloneNode(true); copy.querySelectorAll('[aria-hidden=true], input, textarea, select, button').forEach(child => child.remove()); return copy.textContent; }).join(' ').trim();
        const root = getComputedStyle(document.documentElement);
        const rgb = token => {
            const hex = root.getPropertyValue(token).trim();
            return /^#[0-9a-f]{6}$/i.test(hex) ? `rgb(${[1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16)).join(', ')})` : hex;
        };
        for (const el of document.querySelectorAll('input, textarea, select')) {
            if (!visible(el) || el.type === 'hidden') continue;
            counts.fields = (counts.fields || 0) + 1;
            check(!!name(el), el, 'Control lacks an accessible label');
            if (el.matches('.compact-create-row input, .environment-variable-table input:not([type=checkbox])')) {
                const style = getComputedStyle(el);
                check(style.minHeight === '44px' && style.borderRadius === '6px' && style.paddingLeft === '12px', el, 'Dense inline field recipe drifts');
                check(style.backgroundColor === rgb('--surface') && style.color === rgb('--text'), el, 'Dense inline field colors drift');
            }
        }
        for (const el of document.querySelectorAll('select')) {
            if (!visible(el)) continue;
            counts.selects = (counts.selects || 0) + 1;
            const style = getComputedStyle(el);
            const minimum = el.classList.contains('ui-select-dense') ? 44 : el.classList.contains('ui-select-filter') ? (innerWidth <= 767 ? 44 : 40) : el.classList.contains('ui-select-bulk') ? (innerWidth <= 767 ? 44 : 34) : 48;
            check(el.classList.contains('ui-select'), el, 'Select bypasses the shared recipe');
            check(style.appearance === 'none' && style.backgroundImage !== 'none' && style.backgroundSize === '16px', el, 'Select chevron drifts');
            check(parseFloat(style.minHeight) === minimum && parseFloat(style.paddingRight) === 40, el, 'Select geometry drifts');
            const disabled = el.disabled;
            const transition = el.style.transition;
            el.style.transition = 'none'; // Assert the completed state, not an in-flight color transition.
            el.disabled = true;
            check(getComputedStyle(el).color === rgb('--disabled-fg'), el, 'Disabled select color drifts');
            el.disabled = disabled;
            el.style.transition = transition;
        }
        for (const el of document.querySelectorAll('button')) if (visible(el)) {
            counts.buttons = (counts.buttons || 0) + 1;
            check(el.hasAttribute('type'), el, 'Button lacks explicit type');
            check(!!(el.getAttribute('aria-label') || el.textContent.trim()), el, 'Button lacks a name');
            if (el.matches('.button, .icon-button, .text-button, .copy-inline, .code-block-heading button')) {
                const minimum = innerWidth <= 767 ? 44 : el.matches('.icon-button, .text-button, .button-tertiary:not(.button-small)') ? 40 : el.classList.contains('button-small') ? 38 : 36;
                check(parseFloat(getComputedStyle(el).minHeight) >= minimum, el, 'Command height drifts');
                const disabled = el.disabled, transition = el.style.transition;
                el.style.transition = 'none';
                el.disabled = true;
                check(getComputedStyle(el).color === rgb('--disabled-fg'), el, 'Disabled command color drifts');
                el.disabled = disabled;
                el.style.transition = transition;
            }
        }
        for (const el of document.querySelectorAll('details')) {
            if (!visible(el)) continue;
            counts.disclosures = (counts.disclosures || 0) + 1;
            const summary = el.querySelector(':scope > summary');
            check(el.hasAttribute('data-menu') || el.hasAttribute('data-disclosure'), el, 'Details bypasses shared controller');
            check(summary?.getAttribute('aria-expanded') === String(el.open), el, 'Disclosure expanded state drifts');
            if (el.hasAttribute('data-menu')) check(summary?.hasAttribute('aria-haspopup'), el, 'Menu lacks popup semantics');
        }
        for (const el of document.querySelectorAll('.segmented-control')) if (visible(el)) {
            counts.segments = (counts.segments || 0) + 1;
            check(el.getAttribute('role') === 'group' && !!name(el), el, 'Segmented group semantics drift');
            for (const button of el.querySelectorAll('button')) check(['true', 'false'].includes(button.getAttribute('aria-pressed')), button, 'Segment selection state missing');
            check(el.querySelectorAll('button[aria-pressed=true]').length === 1, el, 'Segmented group must have one selection');
        }
        for (const el of document.querySelectorAll('input[type=radio]')) if (visible(el)) {
            counts.radios = (counts.radios || 0) + 1;
            check(el.classList.contains('ui-radio') && getComputedStyle(el).width === '16px', el, 'Radio bypasses shared component');
        }
        for (const el of document.querySelectorAll('thead th')) check(el.getAttribute('scope') === 'col', el, 'Table column lacks scope');
        check(document.documentElement.scrollWidth <= innerWidth + 1, document.documentElement, 'Horizontal page overflow');
        return { failures, counts };
    });
    results.push({ label, ...result });
    await page.screenshot({ path: resolve(shots, `${label}.png`), fullPage: !await page.locator('[data-editor-viewport]').count() });
}
async function menus(page, label) {
    const parent = page.locator('.mobile-nav > summary');
    if (await parent.isVisible()) await parent.click();
    for (const selector of ['.environment-switcher', '.theme-menu', '.user-menu']) {
        const menu = page.locator(selector).filter({ visible: true }).first();
        if (!await menu.count()) continue;
        await menu.locator(':scope > summary').click();
        await page.waitForTimeout(100);
        const panel = menu.locator(':scope > :not(summary)');
        const box = await panel.boundingBox();
        assert.ok(box && box.x >= 15 && box.x + box.width <= (await page.viewportSize()).width - 15 && box.y >= 0, `${label}: menu outside viewport`);
        if (await parent.isVisible()) assert.equal(await page.locator('.mobile-nav').evaluate(el => el.open), true, 'Nested menu closed its parent navigation');
        await page.keyboard.press('Escape');
    }
    if (await parent.isVisible() && await page.locator('.mobile-nav').evaluate(el => el.open)) await page.keyboard.press('Escape');
    if (await parent.isVisible()) {
        await parent.click();
        const child = page.locator('.mobile-nav .theme-menu');
        await child.locator(':scope > summary').click();
        await parent.click();
        await page.waitForTimeout(100);
        assert.equal(await child.evaluate(el => el.open), false, 'Collapsed navigation left a nested menu open');
    }
}
async function expandedEditor(page, label) {
    const commit = action => Promise.all([
        page.waitForResponse(response => response.url().includes('/update') && response.request().method() === 'POST'),
        action(),
    ]);
    const form = page.locator('.response-form');
    const fieldType = form.locator('.schema-type select').first();
    for (const type of ['string', 'number', 'boolean', 'date', 'object', 'array', 'faker']) {
        if (await fieldType.inputValue() !== type) await commit(() => fieldType.selectOption(type));
        await inspect(page, `${label}-field-${type}`);
        const modes = { string: ['interpolated'], number: ['int', 'float'], boolean: ['random'], date: ['past', 'future', 'between', 'fixed'], array: ['range'] };
        const modeLabel = { string: 'String mode', number: 'Number mode', boolean: 'Boolean value', date: 'Date mode', array: 'Array length mode' };
        for (const mode of modes[type] || []) {
            await commit(() => form.getByRole('combobox', { name: modeLabel[type], exact: true }).first().selectOption(mode));
            await inspect(page, `${label}-${type}-${mode}`);
        }
        if (type === 'object') {
            await commit(() => form.getByRole('button', { name: 'Add nested field', exact: false }).first().click());
            await inspect(page, `${label}-nested-object`);
        }
        if (type === 'array') {
            const item = form.getByRole('button', { name: 'Choose item type', exact: true });
            if (await item.count()) await commit(() => item.click());
            await inspect(page, `${label}-array-item`);
        }
        if (type === 'faker') {
            const picker = form.locator('[data-faker-picker]').first();
            await picker.locator('summary').click();
            await inspect(page, `${label}-faker-menu`);
            const geometry = await picker.locator('.faker-search input').evaluate(el => ({ border: getComputedStyle(el).borderWidth, height: getComputedStyle(el).minHeight }));
            assert.deepEqual(geometry, { border: '0px', height: '40px' }, 'Faker search has a duplicate field perimeter');
            await page.keyboard.press('Escape');
            await form.locator('.schema-args > summary').first().click();
            await inspect(page, `${label}-faker-arguments`);
            await page.keyboard.press('Escape');
        }
    }
    await commit(() => form.getByRole('button', { name: 'List of objects', exact: true }).click());
    await inspect(page, `${label}-list-fixed`);
    await commit(() => form.locator('.builder-root-count select').selectOption('range'));
    await inspect(page, `${label}-list-range`);
    await commit(() => page.locator('.selection-settings').getByRole('button', { name: 'Sequence', exact: true }).click());
    await inspect(page, `${label}-sequence`);
    await commit(() => page.locator('.selection-settings').getByRole('button', { name: 'Rule-based', exact: true }).click());
    const card = page.locator('.selection-response-card').first();
    await card.locator('.response-row-details > summary').click();
    await card.locator('summary').filter({ hasText: 'Conditions' }).click();
    await commit(() => card.getByRole('button', { name: 'Add condition', exact: true }).click());
    await inspect(page, `${label}-rule-conditions`);
    await commit(() => card.getByLabel(/^Operator/).selectOption('exists'));
    await inspect(page, `${label}-rule-exists`);
    await page.getByRole('tab', { name: /^Callback/ }).click();
    await commit(() => page.locator('.callback-response-row').first().getByRole('button', { name: /^Configure callback/ }).click());
    const callback = page.locator('.callback-form');
    await inspect(page, `${label}-callback-json`);
    for (const heading of ['Retry policy', 'Signing']) await callback.locator('summary').filter({ hasText: heading }).click();
    await inspect(page, `${label}-callback-expanded`);
    await commit(() => callback.getByRole('button', { name: 'Builder', exact: true }).click());
    await commit(() => callback.getByRole('button', { name: 'Add field', exact: false }).last().click());
    await inspect(page, `${label}-callback-builder`);
    await commit(() => callback.locator('.schema-type select').first().selectOption('faker'));
    const callbackPicker = callback.locator('[data-faker-picker]').first();
    await callbackPicker.locator('summary').click();
    await inspect(page, `${label}-callback-faker`);
    const searchDisplay = await callbackPicker.locator('.faker-search').evaluate(el => getComputedStyle(el).display);
    assert.equal(searchDisplay, 'flex', 'Field labels changed the composed Faker search layout');
    await page.keyboard.press('Escape');
    await callback.locator('.schema-args > summary').first().click();
    await inspect(page, `${label}-callback-arguments`);
    assert.equal(await callback.locator('.schema-args label').first().evaluate(el => getComputedStyle(el).display), 'grid', 'Field labels changed the composed argument layout');
    await page.keyboard.press('Escape');
    await commit(() => callback.getByRole('button', { name: 'List of objects', exact: true }).click());
    await commit(() => callback.locator('.builder-root-count select').selectOption('range'));
    await inspect(page, `${label}-callback-list-range`);
    await page.getByRole('button', { name: 'Endpoint history', exact: true }).click();
    await inspect(page, `${label}-history`);
    await commit(() => page.locator('#endpoint-history-dialog').getByRole('button', { name: 'Compare with current', exact: true }).first().click());
    await inspect(page, `${label}-history-diff`);
    await page.keyboard.press('Escape');
}
try {
    for (const theme of ['light', 'dark']) {
        const page = await browser.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/dashboard`);
        await page.evaluate(value => localStorage.setItem('mockdeck-theme', value), theme);
        for (const width of [1440, 1001, 1000, 740, 390]) {
            await page.setViewportSize({ width, height: 800 });
            for (const [name, path] of [['endpoints', '/dashboard'], ['requests', '/dashboard/logs'], ['callbacks', '/dashboard/logs?type=callbacks'], ['transfer', '/dashboard/config'], ['environments', '/dashboard/environments'], ['docs', '/dashboard/docs'], ['create', '/dashboard/endpoints/create']]) {
                await page.goto(base + path);
                await inspect(page, `${theme}-${width}-${name}`);
                if (name === 'endpoints') {
                    assert.ok(await page.locator('.pagination-nav').count(), 'Populated registry lacks tokenized pagination');
                    await page.getByRole('button', { name: 'Next page', exact: true }).click();
                    await page.waitForFunction(() => document.querySelector('.pagination-current')?.textContent.trim() === '2');
                    await inspect(page, `${theme}-${width}-pagination`);
                    await page.getByRole('button', { name: 'Previous page', exact: true }).click();
                    await page.waitForFunction(() => document.querySelector('.pagination-current')?.textContent.trim() === '1');
                    await page.getByRole('button', { name: 'Go to page 2', exact: true }).click();
                    await page.waitForFunction(() => document.querySelector('.pagination-current')?.textContent.trim() === '2');
                    await menus(page, `${theme}/${width}`);
                    await page.getByRole('checkbox', { name: 'Select all endpoints on this page', exact: true }).check();
                    await page.waitForSelector('.selection-bar');
                    await inspect(page, `${theme}-${width}-bulk-actions`);
                    await page.locator('.bulk-tag-picker > summary').click();
                    await inspect(page, `${theme}-${width}-bulk-tags`);
                    await page.keyboard.press('Escape');
                    if ([1440, 390].includes(width)) {
                        await page.getByRole('searchbox', { name: 'Search endpoints', exact: true }).fill('UI audit no matching result XYZ');
                        await page.waitForSelector('.empty-state');
                        await inspect(page, `${theme}-${width}-empty-registry`);
                    }
                }
                if (name === 'requests' && await page.locator('.log-row').count()) {
                    await page.locator('.log-row').first().locator('td').first().click();
                    await page.waitForSelector('.log-row[aria-expanded="true"]');
                    assert.equal(new URL(page.url()).pathname, new URL(base + '/dashboard/logs').pathname, 'Request detail navigated to its endpoint link');
                    await inspect(page, `${theme}-${width}-request-detail`);
                }
                if (['requests', 'callbacks'].includes(name) && [1440, 390].includes(width)) {
                    await page.getByRole('button', { name: 'Comfortable', exact: true }).click();
                    await inspect(page, `${theme}-${width}-${name}-comfortable`);
                    await page.getByRole('searchbox').fill('UI audit no matching result XYZ');
                    await page.waitForSelector('.mini-empty-state, .empty-table');
                    await inspect(page, `${theme}-${width}-${name}-empty`);
                }
                if (name === 'environments') {
                    await page.locator('.danger-zone > summary').click();
                    await inspect(page, `${theme}-${width}-environment-danger`);
                    assert.ok(await page.locator('[wire\\:click="renameSelected"].button-primary').count());
                }
                if (name === 'transfer' && width === 1440) {
                    await page.locator('#config-file').setInputFiles({ name: 'ui-audit.json', mimeType: 'application/json', buffer: Buffer.from(fixture.import) });
                    await page.getByRole('button', { name: 'Preview import', exact: true }).waitFor();
                    await page.getByRole('button', { name: 'Preview import', exact: true }).click();
                    await page.waitForSelector('.import-items');
                    await inspect(page, `${theme}-${width}-import-preview`);
                }
            }
            await page.goto(`${base}/dashboard/endpoints/${fixture.endpoint}/edit`);
            for (const tab of ['Request', 'Matching', 'Response', 'Callback']) {
                await page.getByRole('tab', { name: new RegExp('^' + tab) }).click();
                await inspect(page, `${theme}-${width}-editor-${tab.toLowerCase()}`);
            }
            await page.getByRole('tab', { name: /^Response/ }).click();
            await page.locator('.selection-response-card').first().getByRole('button', { name: 'Edit', exact: true }).click();
            await page.waitForSelector('.response-form');
            await page.locator('.response-form').getByRole('button', { name: 'Template', exact: true }).click();
            await page.waitForSelector('#template-locale');
            await inspect(page, `${theme}-${width}-template`);
            await page.locator('.response-form').getByRole('button', { name: 'Builder', exact: true }).click();
            await page.waitForSelector('.schema-builder');
            await page.locator('.schema-builder').getByRole('button', { name: 'Add field', exact: false }).last().click();
            await page.waitForSelector('.schema-row');
            await inspect(page, `${theme}-${width}-builder`);
            if ([1440, 390].includes(width)) await expandedEditor(page, `${theme}-${width}`);
        }
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 800 });
            for (const [name, html] of Object.entries(fixture.shells)) {
                await page.route(`${base}/__ui-audit/${name}`, route => route.fulfill({ contentType: 'text/html', body: html.replaceAll('http://localhost', base) }));
                await page.goto(`${base}/__ui-audit/${name}`);
                await inspect(page, `${theme}-${width}-standalone-${name}`);
                await menus(page, `${theme}/${width}/${name}`);
            }
        }
        await page.close();
    }
    await writeFile(resolve(shots, 'results.json'), JSON.stringify({ errors, results }, null, 2));
    const failures = results.filter(row => row.failures.length).map(row => ({ label: row.label, failures: row.failures }));
    assert.deepEqual(errors, [], 'Browser script errors');
    assert.equal(failures.length, 0, JSON.stringify(failures.slice(0, 4), null, 2));
    console.log(`${results.length} application-wide page/state snapshots pass in both themes.`);
} finally { await browser.close(); }
