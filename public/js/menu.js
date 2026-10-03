(function () {
    'use strict';

    const menus = () => [...document.querySelectorAll('details[data-menu]')];
    const trigger = (menu) => menu.querySelector(':scope > summary');
    const panel = (menu) => menu.querySelector(':scope > :not(summary)');
    const sync = () => menus().forEach((menu) => {
        const summary = trigger(menu);
        if (!summary) return;
        const expanded = String(menu.open);
        const popup = panel(menu)?.getAttribute('role') === 'menu' ? 'menu' : 'true';
        if (summary.getAttribute('aria-expanded') !== expanded) summary.setAttribute('aria-expanded', expanded);
        if (summary.getAttribute('aria-haspopup') !== popup) summary.setAttribute('aria-haspopup', popup);
    });
    const options = (menu) => [...menu.querySelectorAll('[role="menuitemradio"], [role="option"], button, a')]
        .filter((item) => !item.hidden && !item.closest('[hidden]') && !item.disabled && item.getClientRects?.().length !== 0);

    const closeDescendants = (menu) => menus().forEach((child) => { if (child !== menu && menu.contains?.(child)) close(child); });

    function close(menu, focus = false) {
        if (!menu) return;
        closeDescendants(menu);
        if (!menu.open) return;
        menu.open = false;
        window.MockDeckPanels?.close(panel(menu));
        if (focus) trigger(menu)?.focus();
    }

    function open(menu, focus = false, last = false) {
        menus().forEach((other) => { if (other !== menu && !other.contains?.(menu)) close(other); });
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
            menus().forEach((other) => { if (other !== menu && !other.contains?.(menu)) close(other); });
            window.MockDeckPanels?.open(trigger(menu), panel(menu));
        } else {
            closeDescendants(menu);
            window.MockDeckPanels?.close(panel(menu));
        }
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

    document.addEventListener('livewire:navigated', () => { menus().forEach((menu) => close(menu)); sync(); });
    sync();
    new MutationObserver(sync).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'aria-expanded', 'aria-haspopup'] });
    window.MockDeckMenu = { open, close };
}());
