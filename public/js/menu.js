(function () {
    'use strict';

    const menus = () => [...document.querySelectorAll('details[data-menu]')];
    const trigger = (menu) => menu.querySelector(':scope > summary');
    const panel = (menu) => menu.querySelector(':scope > :not(summary)');
    const options = (menu) => [...menu.querySelectorAll('[role="menuitemradio"], [role="option"], button, a')]
        .filter((item) => !item.hidden && !item.closest('[hidden]') && !item.disabled);

    function close(menu, focus = false) {
        if (!menu?.open) return;
        menu.open = false;
        window.MockDeckPanels?.close(panel(menu));
        if (focus) trigger(menu)?.focus();
    }

    function open(menu, focus = false, last = false) {
        menus().forEach((other) => { if (other !== menu) close(other); });
        menu.open = true;
        window.MockDeckPanels?.open(trigger(menu), panel(menu));
        if (focus) {
            const items = options(menu);
            (last ? items.at(-1) : items.find((item) => item.getAttribute('aria-checked') === 'true') || items[0])?.focus();
        }
    }

    document.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (!menu.matches?.('details[data-menu]')) return;
        trigger(menu)?.setAttribute('aria-expanded', String(menu.open));
        if (menu.open) {
            menus().forEach((other) => { if (other !== menu) close(other); });
            window.MockDeckPanels?.open(trigger(menu), panel(menu));
        } else window.MockDeckPanels?.close(panel(menu));
    }, true);

    document.addEventListener('click', (event) => {
        if (event.target.closest?.('dialog')) return;
        const menu = event.target.closest?.('details[data-menu]');
        if (!menu) {
            menus().forEach((item) => close(item));
            return;
        }
        if (event.target.closest('a, button') && !event.target.closest('label, [data-faker-search]')) {
            close(menu);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.target.closest?.('dialog[open]')) return;
        const menu = event.target.closest?.('details[data-menu]');
        if (event.key === 'Escape') {
            const openMenu = menu?.open ? menu : menus().find((item) => item.open);
            if (openMenu) { event.preventDefault(); close(openMenu, true); }
            return;
        }
        if (!menu) return;
        if (event.target === trigger(menu) && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            open(menu, true, event.key === 'ArrowUp');
            return;
        }
        if (menu.hasAttribute('data-faker-picker')) return; // Its searchable listbox owns keyboard selection.
        const items = options(menu);
        const index = items.indexOf(event.target.closest('a, button'));
        if (index < 0) return;
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items[next]?.focus();
        }
    });

    document.addEventListener('livewire:navigated', () => menus().forEach((menu) => close(menu)));
    window.MockDeckMenu = { open, close };
}());
