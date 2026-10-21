import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8').replaceAll('\r\n', '\n');
const sha256 = (text) => createHash('sha256').update(text).digest('hex');

test('application license preserves the official PolyForm Strict terms and correct attribution', () => {
    const license = read('LICENSE');
    const notice = '\nRequired Notice: Copyright (c) 2026 Rusiru Rathmina (https://github.com/ru5iru)\n';
    assert.ok(license.endsWith(notice));
    // Official https://polyformproject.org/licenses/strict/1.0.0.txt,
    // excluding MockDeck's separate copyright notice; changes need legal review.
    const terms = license.slice(0, -notice.length).trimEnd() + '\n';
    assert.equal(sha256(terms), 'e2361f52ad5be22b937a6e983c824a534c5cffa454b6c34af2f8ce0c2cdf7c1a');
    assert.doesNotMatch(license, /MIT License/);
    assert.equal(JSON.parse(read('composer.json')).license, 'proprietary');
});

test('historical MIT and third-party font notices remain intact and separately scoped', () => {
    assert.equal(sha256(read('licenses/MIT-HISTORICAL.txt')), 'd8acf2c771417aa38e70bd4266e82e21e0ed0e9336784910d5cabd5cd0162836');
    assert.equal(sha256(read('public/fonts/jetbrains-mono/OFL.txt')), '403581b69dac5cff4079205e01c6b467e56af449ecbd7247693ddb1baafa005b');
    const guide = read('docs/LICENSING.md');
    for (const term of ['b10e7a3', 'not an alternative MIT license', 'does not relicense third-party', 'BSD-3-Clause', 'Apache-2.0', 'SIL Open Font License 1.1']) {
        assert.ok(guide.includes(term), `Licensing boundary: ${term}`);
    }
});

test('README and in-app help disclose the source-available license and preserved rights', () => {
    for (const path of ['README.md', 'resources/views/admin/docs.blade.php']) {
        const text = read(path);
        for (const term of ['source-available', 'PolyForm Strict License 1.0.0', 'Rusiru Rathmina', 'modify or redistribute', 'Commercial use requires separate permission', 'Previously released MIT versions retain their MIT rights', 'Third-party packages and fonts retain their own licenses']) {
            assert.ok(text.includes(term), `${path}: ${term}`);
        }
        assert.ok(text.includes('docs/LICENSING.md'), path);
        assert.ok(text.includes('https://github.com/ru5iru'), `${path}: copyright holder profile`);
    }
    const help = read('resources/views/admin/docs.blade.php');
    assert.ok(help.includes('href="#licensing"'));
    assert.ok(help.includes('id="licensing" class="card docs-card docs-wide"'));
    assert.ok(read('docs/USER_GUIDE.md').includes('](LICENSING.md)'));
});
