import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

const read = (path) => readFileSync(path, 'utf8');

test('README documentation links resolve to maintained source guides', () => {
    const links = [...read('README.md').matchAll(/\]\((docs\/[^)#]+)(?:#[^)]*)?\)/g)].map(match => match[1]);
    assert.ok(links.length > 0);
    for (const path of links) {
        assert.ok(existsSync(resolve(path)), `Missing documentation: ${path}`);
    }
});

test('README links every maintained operator and implementation guide', () => {
    const readme = read('README.md');

    for (const path of [
        'docs/USER_GUIDE.md',
        'docs/CONFIG_IMPORT_EXPORT.md',
        'docs/VALIDATION.md',
        'docs/ARCHITECTURE.md',
        'docs/UI_GUIDE.md',
        'docs/HTTPS.md',
    ]) {
        assert.ok(readme.includes(`](${path})`), `${path} is linked from README`);
    }
});

test('user guide covers every implemented operator feature', () => {
    const guide = read('docs/USER_GUIDE.md');

    for (const topic of [
        'Create an endpoint from cURL',
        'Understand request matching',
        'Manage the endpoint registry',
        'Environments and variables',
        'Configure response pools',
        'Build a response template',
        'Template language reference',
        'Inspect the request log',
        'Export and import configuration',
        'Version history, diff, and restore',
        'Asynchronous callbacks',
        'Protected template endpoints',
        'Operations and validation',
        'Current limitations',
    ]) {
        assert.ok(guide.includes(topic), `${topic} is documented`);
    }
});

test('in-app documentation exposes the complete workflow sections', () => {
    const page = read('resources/views/admin/docs.blade.php');

    for (const id of [
        'getting-started',
        'service-startup',
        'local-https',
        'connection-troubleshooting',
        'endpoint-registry',
        'environments',
        'request-matching',
        'response-pools',
        'response-templating',
        'template-language',
        'builder-view',
        'json-view',
        'template-seeding',
        'template-errors',
        'config-transfer',
        'version-history',
        'request-log',
        'async-callbacks',
        'preferences-accessibility',
        'serving-errors',
        'shortcuts',
    ]) {
        assert.match(page, new RegExp(`id="${id}"`), `${id} section exists`);
        assert.match(page, new RegExp(`href="#${id}"`), `${id} is linked from contents`);
    }
});

test('Markdown and in-app setup explain same-port TLS and separate Windows/WSL trust', () => {
    for (const path of ['docs/HTTPS.md', 'resources/views/admin/docs.blade.php']) {
        const guide = read(path);
        for (const command of [
            'mkdir -p docker/nginx/certs',
            'mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1',
            'certutil -user -addstore Root',
            'COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml',
            'docker compose up -d --build --wait',
            'docker compose port nginx 80',
            'nginx -t',
            'NET::ERR_CERT_AUTHORITY_INVALID',
            'rootCA-key.pem',
        ]) assert.ok(guide.includes(command), `${path}: ${command}`);
        assert.ok(guide.includes('http://localhost:18473'));
        assert.ok(guide.includes('https://localhost:18473'));
    }
    assert.ok(read('docs/USER_GUIDE.md').includes('](HTTPS.md)'));
});


test('first-time setup follows the same Make flow in Markdown and dashboard help', () => {
    for (const path of ['README.md', 'docs/SETUP.md', 'docs/USER_GUIDE.md', 'resources/views/admin/docs.blade.php']) {
        const guide = read(path);
        for (const command of ['make setup', 'make up', 'make health', 'make https-setup']) {
            assert.ok(guide.includes(command), `${path}: ${command}`);
        }
    }
});


test('MCP server documentation covers active transport and credential lifecycle', () => {
    assert.ok(read('README.md').includes('](docs/MCP.md)'));
    const guide = read('docs/MCP.md');
    for (const command of ['mockdeck:mcp-credential:generate', 'mockdeck:mcp-credential:list', 'mockdeck:mcp-credential:revoke']) {
        assert.ok(guide.includes(command), command);
    }
    assert.ok(read('docs/USER_GUIDE.md').includes('](MCP.md#credential-lifecycle-cli)'));
    for (const term of ['Streamable HTTP', '18 tools', 'INCONCLUSIVE_EVIDENCE', 'MCP_TOOLS.json', 'peek_next_response', 'preview_template', 'preview_postman_import', 'apply_postman_import', 'Troubleshooting']) assert.ok(guide.includes(term), term);
});


test('isolated run help documents both routes and the ordinary traffic boundary', () => {
    for (const path of ['docs/MCP.md', 'resources/views/admin/docs.blade.php']) {
        const guide = read(path);
        for (const term of ['X-MockDeck-Test-Run', '/_mockdeck/run/', '/mcp', 'ordinary']) {
            assert.ok(guide.includes(term), `${path}: missing ${term}`);
        }
    }
});
