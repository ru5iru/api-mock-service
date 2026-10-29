(function () {
    'use strict';
    // Only non-sensitive presentation preferences are retained.
    const sidebarKey = 'mockdeck:sidebar-collapsed';
    const shortcutKey = 'mockdeck:single-key-shortcuts';
    const read = (key, fallback) => { try { return localStorage.getItem(key) ?? fallback; } catch (_) { return fallback; } };
    const write = (key, value) => { try { localStorage.setItem(key, value); } catch (_) { /* The current page remains usable. */ } };
    let collapsed = read(sidebarKey, 'false') === 'true';
    let shortcuts = read(shortcutKey, 'true') !== 'false';
    let drawerOpen = false;
    function sync() {
        const narrow = window.matchMedia('(max-width: 1100px)').matches;
        document.body.dataset.workbenchCollapsed = String(collapsed);
        document.body.dataset.workbenchDrawer = String(drawerOpen);
        document.querySelectorAll('[data-workbench-toggle]').forEach(button => button.setAttribute('aria-expanded', String(narrow ? drawerOpen : !collapsed)));
        document.querySelectorAll('[data-single-key-shortcuts]').forEach(input => { input.checked = shortcuts; });
        const panel = document.getElementById('workbench-context');
        if (panel) panel.inert = narrow ? !drawerOpen : collapsed;
        window.dispatchEvent(new Event('resize'));
    }
    document.addEventListener('click', event => {
        const toggle = event.target.closest('[data-workbench-toggle]');
        if (!toggle) return;
        if (window.matchMedia('(max-width: 1100px)').matches) drawerOpen = !drawerOpen;
        else { collapsed = !collapsed; write(sidebarKey, String(collapsed)); }
        sync();
    });
    document.addEventListener('change', event => {
        if (!event.target.matches('[data-single-key-shortcuts]')) return;
        shortcuts = event.target.checked;
        write(shortcutKey, String(shortcuts));
        sync();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && drawerOpen) {
            drawerOpen = false;
            document.querySelector('[data-workbench-toggle]')?.focus();
            sync();
        }
    });
    document.addEventListener('livewire:navigated', () => { drawerOpen = false; sync(); });
    window.matchMedia('(max-width: 1100px)').addEventListener('change', () => { drawerOpen = false; sync(); });
    window.MockDeckWorkbench = { singleKeyShortcutsEnabled: () => shortcuts };
    sync();
}());
