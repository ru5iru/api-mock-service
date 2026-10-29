// Operate the visible workspace UI; legacy Request/Matching/Response URLs remain supported separately.
export async function openEditorSection(page, section) {
    const workspace = ['Request', 'Matching', 'Response'].includes(section) ? 'Editor' : section;
    const tab = page.locator('[data-endpoint-tabs]').getByRole('tab', { name: new RegExp(`^${workspace}`) });
    if (await tab.getAttribute('aria-selected') !== 'true') await tab.click();
    const visiblePanel = workspace === 'Editor' ? '#panel-editor' : `#panel-${workspace.toLowerCase()}`;
    await page.locator(visiblePanel).waitFor({ state: 'visible' });
    if (section === 'Request' || section === 'Matching') {
        await page.locator('[data-editor-subtabs]').getByRole('tab', { name: new RegExp(`^${section}`) }).click();
    } else if (section === 'Response') {
        await page.locator('#response-pane-heading').scrollIntoViewIfNeeded();
    }
}
