(function () {
    'use strict';
    const bound = new WeakSet();
    const escape = value => value.replace(/[&<>]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[character]);
    const highlight = value => escape(value).replace(/("(?:\\.|[^"\\])*")(\s*:)?|\b(?:true|false|null)\b|-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/g, (match, string, colon) =>
        string ? `<span class="${colon ? 'code-key' : 'code-string'}">${string}</span>${colon || ''}` : `<span class="code-literal">${match}</span>`);
    function update(editor) {
        const input = editor.querySelector('[data-static-code-input]');
        const mirror = editor.querySelector('.static-code-highlight');
        const gutter = editor.querySelector('.static-code-gutter');
        if (!input || !mirror || !gutter) return;
        const lines = input.value.split('\n');
        mirror.innerHTML = lines.map(line => `<span class="code-source-line">${highlight(line) || ' '}</span>`).join('');
        gutter.innerHTML = lines.map((_, index) => `<span>${index + 1}</span>`).join('');
        editor.classList.add('is-highlighted');
        mirror.style.width = `${input.clientWidth}px`;
        [...mirror.children].forEach((line, index) => { gutter.children[index].style.height = `${line.getBoundingClientRect().height}px`; });
        const scroll = () => { mirror.scrollTop = gutter.scrollTop = input.scrollTop; mirror.scrollLeft = input.scrollLeft; };
        scroll();
        if (!bound.has(input)) {
            input.addEventListener('input', () => update(editor));
            input.addEventListener('scroll', scroll, { passive: true });
            bound.add(input);
        }
    }
    let frame;
    const refresh = () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => document.querySelectorAll('[data-static-code-editor]').forEach(update));
    };
    new MutationObserver(records => {
        if (records.some(record => !record.target.closest?.('.static-code-highlight, .static-code-gutter'))) refresh();
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-editing-response', 'value', 'style'] });
    window.addEventListener('resize', refresh);
    document.addEventListener('livewire:navigated', refresh);
    document.fonts?.ready.then(refresh);
    refresh();
}());
