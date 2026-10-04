import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/js/panels.js', import.meta.url), 'utf8');
function setup({ content = 132, border = 1, height = 900, top = 64 } = {}) {
    const window = { innerHeight: height, addEventListener() {} };
    const document = {
        body: {}, documentElement: { clientWidth: 1440 }, addEventListener() {},
        querySelector: selector => selector === '.topbar' ? { getBoundingClientRect: () => ({ bottom: 64 }) } : null,
    };
    vm.runInNewContext(source, {
        window, document, getComputedStyle: () => ({ borderTopWidth: String(border), borderBottomWidth: String(border) }),
        MutationObserver: class { observe() {} }, requestAnimationFrame() {}, cancelAnimationFrame() {},
    });
    const anchor = { getBoundingClientRect: () => ({ top, bottom: top + 40, left: 100 }), closest: () => null };
    const panel = {
        style: {}, dataset: {}, scrollHeight: content,
        getBoundingClientRect: () => ({ width: 290 }), hasAttribute: () => false,
    };
    return { window, anchor, panel, position: () => window.MockDeckPanels.position(anchor, panel) };
}

test('popup max-height includes borders instead of clipping fitting content', () => {
    for (const border of [0, 1, 1.5, 2]) {
        const { panel, position } = setup({ content: 132, border });
        position();
        assert.equal(parseFloat(panel.style.maxHeight) - 2 * border, 132);
    }
});

test('popup placement recovers its height after a constrained viewport grows', () => {
    const { window, panel, position } = setup({ content: 1000, height: 300 });
    position();
    assert.equal(panel.style.maxHeight, '172px');
    window.innerHeight = 900;
    position();
    assert.equal(panel.style.maxHeight, '420px');
});

test('placement leaves scrolling to the component instead of adding a second scroller', () => {
    const { panel, position } = setup();
    position();
    assert.equal(Object.hasOwn(panel.style, 'overflowY'), false);
    assert.equal(Object.hasOwn(panel.style, 'overflow'), false);
});
