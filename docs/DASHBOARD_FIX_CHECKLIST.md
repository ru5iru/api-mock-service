# Dashboard fix checklist — 22 October 2026

**Availability: 24/24 actionable items implemented.** No missing implementation was found in this source review. This is an implementation checklist, not a claim that every runtime check was repeated today.

Source: [original critique](DASHBOARD_CRITIQUE_2026-10-22.md), reviewed at `3942fca`. Fixes are in `b6dfc3a` and `83b4776`. The original five priority groups contain six P1 defects; the remaining entries below separate the additional findings and page-coverage observations into individually reviewable items. No new product features are implied by the count.

The original Impeccable workflow sequence and previous verification record are retained in [Dashboard refinement](DASHBOARD_REFINEMENT.md). Workflow names such as adapt, harden, clarify, layout, audit and polish describe the design work; they are not Make targets. This follow-up checked the resulting source and reran the available automated checks.

## Item-by-item availability

Every checked box means the implementation is present in the delivered code. The evidence column distinguishes automated coverage from source inspection; it does not imply a dedicated executable test for every copy change. Paths are relative to this document.

| ID | Available | Finding → implemented fix | Source evidence | Verification evidence |
| --- | --- | --- | --- | --- |
| F01 | [x] | P1: Mobile Environment actions clipped → shrinking grid tracks, bounded cards and real internal table scrolling; actions remain reachable. | [CSS](../public/css/app.css), [Environment view](../resources/views/livewire/admin/environment-manager.blade.php) | [Frontend regressions](../tests/Frontend/critique-regressions.test.mjs); [browser regressions](../tests/Browser/critique-regressions.mjs) check individual button bounds after internal scrolling. |
| F02 | [x] | P1: Invalid saved-variable edits fail silently → exact nested validation paths, visible friendly errors, input association and focus. | [EnvironmentManager](../app/Livewire/Admin/EnvironmentManager.php), [validation helper](../public/js/validation.js) | [EnvironmentDraftsTest](../tests/Feature/EnvironmentDraftsTest.php) checks rejection and unchanged stored key; browser suite checks focus and ARIA. |
| F03 | [x] | P1: Environment switching loses edits → per-Environment draft cache; saving one variable preserves sibling drafts and clears saved secret replacements. | [EnvironmentManager](../app/Livewire/Admin/EnvironmentManager.php) | EnvironmentDraftsTest covers switching, name/new-variable drafts, sibling saves and secret cache clearing. |
| F04 | [x] | P1: History text squeezed → specific wide-dialog rule and container-aware stacked actions/diffs. | [CSS](../public/css/app.css), [History view](../resources/views/livewire/admin/revision-history.blade.php) | Frontend regressions; browser suite checks readable text at 390/740/1440px, both themes, and 200% CSS zoom. |
| F05 | [x] | P1: Enter on Copy opens Edit → row navigation handles Enter only when the row itself is the target. | [Dashboard shell](../resources/views/layouts/dashboard.blade.php) | Frontend VM test executes the handler for row/descendant targets; browser suite checks Copy stays on the registry. |
| F06 | [x] | P1: Callback log shows mutable current URL → render the masked recorded attempt target. | [Callback view](../resources/views/livewire/admin/callback-log.blade.php) | [CallbackLogEvidenceTest](../tests/Feature/CallbackLogEvidenceTest.php) deliberately gives recorded/current targets different values and checks secret absence. |
| F07 | [x] | Callback diagnosis incomplete → attempt disclosure shows sanitized error/outcome, request/response identity, lineage and captured-payload resend semantics. | [Callback view](../resources/views/livewire/admin/callback-log.blade.php), [CallbackLog](../app/Livewire/Admin/CallbackLog.php) | CallbackLogEvidenceTest and browser suite; source inspection confirms resolved headers/body are not displayed. |
| F08 | [x] | Required curl buried → curl entry and parse/credential feedback precede optional settings in DOM order. | [Endpoint form](../resources/views/livewire/admin/endpoint-form.blade.php) | Frontend order assertion and browser Create check. |
| F09 | [x] | Indistinguishable responses → shared Response ID, imported example label and compact body/template summary across Response and Callback tabs. | [Response identity](../resources/views/components/response-identity.blade.php), [Response view](../resources/views/livewire/admin/response-manager.blade.php) | [DashboardFeedbackTest](../tests/Feature/DashboardFeedbackTest.php), frontend shared-component count, browser identity check. |
| F10 | [x] | Perpetual draft wording → selection fingerprint drives saved/unsaved wording and preview badge; separate selection/response/callback save controls retain their scopes. | [ResponseManager](../app/Livewire/Admin/ResponseManager.php), [Response view](../resources/views/livewire/admin/response-manager.blade.php) | DashboardFeedbackTest checks clean → dirty → saved without changing response content; browser status check. |
| F11 | [x] | Update preview lacks consequences → Native/Postman prospective before/after diffs and explicit response-pool retention/replacement policy, with masked secrets and bounded text. | [ImportPreviewDiffer](../app/Services/Imports/ImportPreviewDiffer.php), [ConfigImporter](../app/Services/Config/ConfigImporter.php), [MappedImportService](../app/Services/Imports/MappedImportService.php), [Transfer view](../resources/views/livewire/admin/config-transfer.blade.php) | [ImportPreviewChangesTest](../tests/Feature/ImportPreviewChangesTest.php): read-only preview, apply agreement, retained/deleted responses and credential redaction; browser diffs in both themes. |
| F12 | [x] | Autocomplete ARIA stale after morph → stable option IDs, active-descendant/expanded synchronization, clearing on close and stale-fetch guard. | [Template editor JS](../public/js/template-editor.js) | Frontend regressions; browser suite checks a visible active option after a Livewire morph. |
| F13 | [x] | Errors unassociated with inputs → shared model-path association, aria-invalid, preserved help IDs, alert semantics and focus on new errors. | [Validation helper](../public/js/validation.js), [Environment view](../resources/views/livewire/admin/environment-manager.blade.php), [Login](../resources/views/auth/login.blade.php) | Frontend shell-loading checks; browser invalid-variable focus/ARIA check. Other annotated form branches were source-inspected. |
| F14 | [x] | Escape leaves focused tooltip visible → explicit dismissed state overrides focus/hover until focus leaves. | [Panels JS](../public/js/panels.js), [CSS](../public/css/app.css) | Browser suite focuses Priority, presses Escape and checks actual visibility. |
| F15 | [x] | Hidden callback deep-link filters → visible, independently removable response/request scope chips. | [Callback view](../resources/views/livewire/admin/callback-log.blade.php) | CallbackLogEvidenceTest and browser scope-removal check. |
| F16 | [x] | Mobile callback outcome too far right → status first and target/attempt-details second; target wraps while the table remains internally scrollable. | [Callback view](../resources/views/livewire/admin/callback-log.blade.php), [CSS](../public/css/app.css) | Browser mobile callback inspection; source column-order/containment review. Resend remains a separate table action. |
| F17 | [x] | Relative-date amount lacks units → visible days/years and matching accessible label. | [Builder schema row](../resources/views/livewire/admin/partials/schema-row.blade.php) | Source inspection and previous visual inspection. |
| F18 | [x] | Long docs hard to retrieve → local search, result count, clear action, sticky section picker and measured anchor offsets; cached navigation rebinds handlers safely. | [Docs JS](../public/js/docs.js), [Docs view](../resources/views/admin/docs.blade.php), [CSS](../public/css/app.css) | Browser search/jump and previous documentation smoke in both themes, including return navigation. |
| F19 | [x] | MCP run History has numeric IDs only → show current mock name, falling back to retained revision snapshot name, alongside ID. | [Routes](../routes/web.php), [Run History](../resources/views/admin/run-history.blade.php) | Source inspection of current-name lookup and retained-name fallback. |
| F20 | [x] | Recovery copy lacks action → explicit 419 Refresh session link; 500/503 Try again only for GET, avoiding POST replay. | [Error shell](../resources/views/components/layouts/error.blade.php), [419 view](../resources/views/errors/419.blade.php) | DashboardFeedbackTest checks session action and absence of POST retry; source inspection of GET guard. |
| F21 | [x] | Export/backup labels hide scope → distinguish all endpoints/current scope and explain partial/redacted pre-import backup consequences. | [Transfer view](../resources/views/livewire/admin/config-transfer.blade.php) | Source/copy inspection; original export mechanics retained. |
| F22 | [x] | Collection/tag loading feedback inconsistent → include both filters in shared skeleton/content loading targets. | [Registry view](../resources/views/livewire/admin/endpoint-index.blade.php) | Source inspection of matching loading targets. |
| F23 | [x] | Weight relevance unclear outside Weighted → help explicitly states Sequence and rule selection ignore it, associated with the input. | [Response view](../resources/views/livewire/admin/response-manager.blade.php) | Source/copy inspection; selection engine unchanged. |
| F24 | [x] | Extra Details → Conditions toggle adds recall → Conditions initially open within response Details; operator toggling persists through morphs. | [Response view](../resources/views/livewire/admin/response-manager.blade.php) | Source inspection of open and wire:ignore.self on the Conditions disclosure. |

## Verification ledger

Fresh checks on 22 October 2026:

- [x] `make frontend-test`: **62 tests passed**, including the checklist/reference integrity check added with this document.
- [x] `make setup-test`: **11 tests passed**.
- [x] `make design-validate`: declared light/dark contrast pairs and token usage passed.
- [x] `git diff --check`: passed before packaging.
- [x] Archive integrity and source-byte comparison: passed; application source included, runtime dependencies, secrets and certificate material omitted (including the tracked certificate pair under `docker/docker/nginx/certs/`).
- [ ] `make validate`: attempted; stopped at `docker compose config --quiet` because Docker is unavailable. It did not reach Composer, Pint or PHPUnit.
- [ ] Fresh full PHPUnit / browser rerun: unavailable in this follow-up because PHP/Docker and the earlier temporary runtime are absent.
- [ ] Remote GitHub CI result: not verified. The workflow invokes the frontend/setup/PHP suites and both browser targets; configuration alone is not a passed CI run.

Previously recorded for the implementation commits (not fresh reruns): **387 PHPUnit tests / 3,581 assertions**, Pint, 61 frontend tests, 11 setup tests, design validation, final Impeccable detector exit 0 with `[]`, existing layout/documentation smoke in both themes and the new browser regressions. PHP used for those local checks was **8.4.12**, not the project's Docker/CI PHP 8.3. Browser evidence used emulated Chromium and CSS zoom, not physical devices or screen-reader speech. See the refinement log for limits.

The critique covered every dashboard route/component family and nested source branch. Its unchanged strengths and deliberately unexecuted destructive/outage cases are preserved in the original report; this checklist does not convert source-only branches into claimed live tests or certify WCAG compliance.

## Reproduce in your environment

For an existing installation, merge the delivered source into your checkout while preserving your `.env`, certificate files and database volumes. Do not replace the installation directory with an empty archive extraction or delete volumes.

```bash
make up
make health
make validate
```

For a first installation, follow [Setup](SETUP.md), beginning with `make setup`. The archive includes the Docker/Make configuration, Composer lockfile, application, public assets, tests and documentation; dependencies are installed by the documented setup/build flow.

For browser verification, follow [Validation](VALIDATION.md). `make browser-test` runs the layout/documentation checks. `MOCKDECK_BROWSER_FIXTURE=1 make browser-regression-test` writes test fixtures: use it only with an explicitly disposable database and the documented browser server/environment. Never enable that fixture flag against your normal database. GitHub CI supplies its own disposable database and runs both themes.
