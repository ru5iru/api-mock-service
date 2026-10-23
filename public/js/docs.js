(function () {
    'use strict';
    let observer;
    function setup() {
        const search = document.querySelector('[data-docs-search]');
        const jump = document.querySelector('[data-docs-jump]');
        observer?.disconnect();
        if (!search || !jump) return;
        const bar = search.closest('.docs-searchbar');
        observer = new ResizeObserver(() => document.documentElement.style.setProperty('--docs-navigation-height', `${bar.getBoundingClientRect().height}px`));
        observer.observe(bar);
        const sections = [...document.querySelectorAll('.docs-grid > section[id]')];
        const entries = sections.map(section => ({ section, title: section.querySelector('h2').textContent, text: section.textContent.toLocaleLowerCase() }));
        const filter = () => {
            const query = search.value.trim().toLocaleLowerCase();
            const matches = entries.filter(entry => entry.text.includes(query));
            for (const entry of entries) {
                entry.section.hidden = !matches.includes(entry);
                const link = document.querySelector(`.docs-toc a[href="#${entry.section.id}"]`);
                if (link) link.parentElement.hidden = entry.section.hidden;
            }
            jump.replaceChildren(new Option('Jump to a section…', ''));
            for (const entry of matches) jump.add(new Option(entry.title, entry.section.id));
            document.querySelector('[data-docs-results]').textContent = `${matches.length} of ${entries.length} sections`;
        };
        search.oninput = filter;
        jump.onchange = () => {
            const section = document.getElementById(jump.value);
            if (!section) return;
            section.scrollIntoView({ block: 'start' });
            section.setAttribute('tabindex', '-1');
            section.focus({ preventScroll: true });
        };
        document.querySelector('[data-docs-clear]').onclick = () => { search.value = ''; filter(); search.focus(); };
        filter();
    }
    document.addEventListener('livewire:navigated', setup);
    setup();
}());
