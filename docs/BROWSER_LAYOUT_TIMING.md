# Browser layout timing regressions

The CI failure `light/390: save bar obscures pane` occurs in `endpoint-workspace-smoke.mjs`, before the catalog check in the response-authoring script. It is a different assertion from the earlier catalog failure.

The application schedules editor geometry through `scheduleLogSync` in an animation frame; the sticky action bar also uses ResizeObserver. Resizing a Playwright viewport and making a tab visible do not guarantee those calculations have completed. The previous test immediately read the pane and save-bar rectangles in separate browser calls.

The repaired test reads both rectangles in one browser evaluation and polls within its existing15-second limit for the original15px clearance. It then retains the original clearance assertion. Persistent overlap still fails and now reports pane-bottom/bar-top coordinates, theme, viewport and section. This does not suppress errors, reduce the gap, add blanket retries or change application CSS/geometry.

The accompanying response-authoring script retains the earlier bounded catalog-position wait and its original320px bounds assertion. This cumulative timing patch includes both scripts so applying it does not lose the previous catalog repair.

Application behavior is unchanged. A local browser pass does not certify the remote GitHub Actions run; rerun CI after applying these files.

Local verification on30 October2026: `make browser-editor-test browser-response-test` passed in both themes at1440/1024/390/320px on the disposable SQLite/HTTP harness (PHP8.3.6, Chromium150.0.7871.100, Playwright1.62.1). All84 frontend tests present in this checkout passed; both changed scripts passed `node --check`, and `git diff --check` passed. The remote CI failure itself was supplied as a log; it was not reproduced on the GitHub-hosted runner here. No remote CI, Docker/PostgreSQL or full PHP rerun is claimed for this test-only patch.
