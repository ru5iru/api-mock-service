// Only explicit inspect/back commands move focus; polling and filtering never do.
(() => {
    let pending = null;
    const returnTriggers = new WeakMap();
    function remember(trigger, back) {
        const workspace = trigger.closest('[data-activity-workspace]');
        if (!workspace) return;
        const owner = workspace.closest('[wire\\:id]') ?? workspace;
        if (!back) returnTriggers.set(owner, trigger.dataset.activityTrigger);
        pending = { owner, back };
    }
    document.addEventListener('click', event => {
        if (event.target.closest('[data-stop-row-navigation],a')) return;
        const trigger = event.target.closest('[data-activity-trigger], [data-activity-back]');
        if (trigger) remember(trigger, trigger.hasAttribute('data-activity-back'));
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target.matches('[data-activity-trigger].log-row')) remember(event.target, false);
    }, true);
    new MutationObserver(() => {
        if (!pending) return;
        const { owner, back } = pending;
        if (!owner.isConnected) { pending = null; return; }
        const workspace = owner.matches('[data-activity-workspace]') ? owner : owner.querySelector('[data-activity-workspace]');
        if (!workspace) return;
        const inspector = workspace.querySelector('[data-activity-inspector]');
        if ((!back && !inspector) || (back && inspector)) return;
        const target = back ? [...workspace.querySelectorAll('[data-activity-trigger]')].find(el => el.dataset.activityTrigger === returnTriggers.get(owner)) : inspector;
        pending = null;
        requestAnimationFrame(() => { if (target?.isConnected) target.focus(); });
    }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('livewire:navigating', () => { pending = null; });
})();
