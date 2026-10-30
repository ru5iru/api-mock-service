import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const workflow = readFileSync(new URL('../../.github/workflows/ci.yml', import.meta.url), 'utf8');
const browser = workflow.split('- name: Check dashboard layouts and documentation in both themes')[1].split('- name: Upload browser screenshots')[0];

test('disposable browser CI retains real request evidence required by the Logs journey', () => {
    assert.match(browser, /MOCK_LOG_FILE: 'true'/);
    assert.match(browser, /MOCK_DASHBOARD_AUTH_ENABLED: 'false'/);
    assert.match(browser, /DB_DATABASE: \$\{\{ runner.temp \}\}\/browser.sqlite/);
    const origin = browser.match(/APP_URL: (http:\/\/127\.0\.0\.1:\d+)/)?.[1];
    assert.ok(origin);
    assert.ok(browser.includes(`MOCKDECK_BASE_URL: ${origin}`));
    assert.match(browser, /MOCKDECK_BROWSER_FIXTURE=1 make browser-supporting-test/);
    // Unit tests must still use their independent in-memory, non-logging setup.
    const unit = workflow.split('- name: Run tests')[1].split('- name: Install browser tooling')[0];
    assert.match(unit, /DB_DATABASE: ':memory:'/);
    assert.match(unit, /MOCK_LOG_FILE: 'false'/);
});

test('browser startup bounds readiness, hides successful health HTML, and retains failure diagnostics', () => {
    assert.match(browser, /--output \/dev\/null --max-time 2/);
    assert.match(browser, /kill -0 "\$server_pid"/);
    assert.match(browser, /for attempt in \$\(seq 1 30\)/);
    assert.match(browser, /trap cleanup EXIT/);
    assert.match(browser, /tail -n 160 "\$RUNNER_TEMP\/browser-server.log"/);
    assert.match(browser, /exit "\$status"/);
});
