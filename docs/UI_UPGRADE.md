# UI upgrade checklist and implementation record

Baseline: uploaded master archive `8622ec7d9223c97394a936501762d2d6f909cfa7` (487 files verified against the archive); local Git ancestry remains `44a00e3`. Direction: the supplied version-6 desktop workbench and the 28 October UI/UX upgrade plan. This is the maintained implementation checklist derived from that plan.

## Reconciliation with recent master

| Recent change | Updated requirement |
|---|---|
| Endpoint preflight validation before response-draft saves | Preserve `saveAll` preflight and attempt IDs; invalid endpoint input must not save child drafts. E09 includes the existing global-save characterization suite. |
| Per-scope saved/failed/unconfirmed results and client recovery | F07 uses the shipped results; no replacement save protocol. Test uncertainty and partial outcomes, not just successful saves. |
| First-run/no-response diagnostic and honest labels | E02 preserves the diagnostic 500 when an eligible mock has no response; never label parsed input as callable readiness. |
| Clipboard capture/fallback and action-boundary resolution | E08/L05 preserve delegated clipboard ordering, exact bytes, failure feedback and server-only resolution. |
| Sensitive-output redaction and new regression fixtures | F04, E08, L05 and V04 must preserve these expanded boundaries, including output not handled by the older name-only heuristic. |
| More CI/browser regression coverage | Keep the new first-run/global-save/honest-labels/sensitive-data checks. Adding a smoke test to Make alone does not prove CI ran it. |

## Completion rule

A checkbox means the named scope is implemented and has **two recorded successful verification passes**. Source inspection and a browser pass are different forms of evidence; running the same assertion twice is not an independent review. Partial work stays unchecked. Environment-blocked checks stay BLOCKED and do not become a pass. The ledger names which milestone is delivered; unchecked items are future work, not silently dropped features.

## Current delivery boundary

Delivered scope: shared workbench foundations, registry presentation/interactions, and the first endpoint-workspace milestone (dated verification ledgers below). Create/edit now use Editor / Callback / Settings with Request / Matching subtabs and a Responses pane. Legacy Request / Matching / Response / Callback URL links remain supported. Advanced response/callback, Logs, Environments and transfer redesigns remain unchecked. The source, matching, mutation, import, credential and run-isolation contracts remain authoritative.

## Checklist


**P0:** foundation or safety dependency. **P1:** core redesign release. **P2:** completion/hardening. Checked items have evidence in the ledger below; unchecked items remain pending.

### P0 — shared foundations and interaction contracts

- [x] **F01** Approve the workbench shell, tab mapping and full-registry retention.
- [x] **F02** Define one token mapping, light counterpart, typography, spacing, density, hit targets and elevation rules in the existing UI guide.
- [x] **F03** Inventory all current actions/states using section 3 and keep a migration parity ledger.
- [ ] **F04** Style the existing buttons, fields, selects, switches, badges, menu, dialog, toast, disclosure, tooltip and code surfaces consistently.
- [x] **F05** Implement persistent rail/context/work-area chrome without breaking Livewire navigation, theme resolution or session/local-access state.
- [x] **F06** Establish scroll ownership, pane collapse, focus restoration and measured sticky/action offsets.
- [ ] **F07** Establish truthful Saved / Unsaved / Saving / Failed / Partially saved semantics using existing draft scopes.
- [x] **F08** Reuse the dirty-navigation guard for new explorer/navigation interactions; test failure and partial-save paths.
- [ ] **F09** Define accessible shortcuts, tooltip dismissal, announcements and compact-target geometry.

**Gate:** all shared components have light/dark, keyboard, disabled/loading/error and 320 px examples before feature pages are migrated. These are implementation deliverables, not new files created by this planning task.

### P1 — endpoint registry and explorer

- [x] **R01** Replace endpoint cards with continuous aligned rows, preserving full identities and contextual details.
- [x] **R02** Preserve search, method/state/collection/tag filters, sorting, counts and pagination.
- [x] **R03** Preserve single-row enable, copy, edit, duplicate and delete with explicit focus/hover access.
- [x] **R04** Preserve page/individual selection and every bulk operation, including move and tags.
- [x] **R05** Implement explorer search/collection navigation with bounded loading and correct flat grouping.
- [ ] **R06** Design database-empty, collection-empty, filtered-empty, loading and deleted-current-endpoint states.
- [x] **R07** Place embedded recent requests without restoring a wall of dashboard cards.

**Acceptance:** a registry containing long names/paths, unfiled items, many tags, disabled/no-response entries and multiple pages remains fully manageable; the explorer never exposes run-owned mocks.

### P1 — endpoint create/edit: Request and Matching

- [x] **E01** Migrate create/edit into a shared Editor / Callback / Settings layout; preserve old tab deep links through an explicit compatibility mapping.
- [x] **E02** Implement create-before-response guidance and accurate serving-readiness states.
- [ ] **E03** Move cURL import/source editing to the shared dialog/pane flow while preserving parser validation/warnings.
- [x] **E04** Display source URL separately from real mock invocation instructions.
- [ ] **E05** Preserve coarse header policies, subordinate-choice memory and real defaults.
- [ ] **E06** Preserve field exclusions, Postman token provenance and path-segment editing.
- [ ] **E07** Keep complete live canonical preview, variant, hash copy and duplicate detection.
- [ ] **E08** Preserve credential warnings, Mask detected secrets and explicit-copy resolution/error behavior.
- [x] **E09** Preserve dirty drafts, action scoping, first-error focus and failed/partial-save reconciliation.

**Acceptance:** equivalent configurations produce byte-identical canonical strings/hashes and native exports; source and copied cURL remain valid without escaping regressions; no new request is executed by parsing or previewing.

### P1 — responses, templates and selection

- [x] **S01** Implement response identity/selector/list without losing drafts held for other responses.
- [ ] **S02** Preserve status range, JSON headers, raw Static body, weight, delay and Delete safeguards.
- [ ] **S03** Migrate Template/Static and Builder/JSON with the existing catalog and schema controls.
- [ ] **S04** Preserve nested builder fields, argument inputs, reorder alternatives and unsupported-builder fallback.
- [ ] **S05** Migrate preview, issues/quick fixes, locale and all seed modes; label synthetic samples accurately.
- [ ] **S06** Preserve Weighted default and probability summaries.
- [ ] **S07** Migrate Sequence order/exhaustion/preview/reset with explicit active-environment scope.
- [ ] **S08** Migrate Rule conditions/priorities/default validation without changing grammar or order.
- [ ] **S09** Migrate the four existing fault types, limits and enabled/probability summaries.
- [ ] **S10** Preserve response History, source example labels, save/cancel and per-response dirty state.

**Acceptance:** Weighted outcomes remain governed by existing services; 503→503→200 sequence/reset works; Rule match and fallback work; preview leaves runtime state untouched; raw text remains raw; configured faults retain existing behavior.

### P1 — endpoint Callback and Settings

- [ ] **C01** Bind callback editing visibly to a selected response.
- [ ] **C02** Preserve URL/method, headers, builder/JSON body, preview and enable state.
- [ ] **C03** Migrate timing, attempts, backoff, timeout, signing and saved-secret retention.
- [ ] **C04** Keep Test saved callback separate from payload preview and disabled for unsaved relevant changes.
- [ ] **C05** Link real saved test outcomes/attempts and related callback Logs.
- [ ] **C06** Move name/collection/tags/priority/environment availability into Settings with current save boundaries.
- [ ] **C07** Preserve enabled/override semantics, inline organization creation and destructive safeguards.

**Acceptance:** callback test uses saved configuration; no secret becomes readable; every real environment override remains editable; callback/network behavior is unchanged.

### P1 — request and callback Logs

- [ ] **L01** Build the shared stable list/inspector frame and narrow Back-to-list behavior.
- [ ] **L02** Preserve Requests/Callbacks and aliases; defer unsupported All activity/CSV additions explicitly.
- [ ] **L03** Preserve every existing filter, grouping/time/density control and result/window label.
- [ ] **L04** Preserve Live/Paused/detail-open polling behavior without selection/focus jumps.
- [ ] **L05** Migrate complete mismatch/nearest-candidate diagnostics and redacted cURL copy.
- [ ] **L06** Migrate callback recorded-target/outcome/lineage details and Resend behavior.
- [ ] **L07** Preserve endpoint, response and triggering-request cross-links, including deleted-record states.
- [ ] **L08** Distinguish mocked failures, no match, selection exhaustion and callback delivery failure where real data supports it.

**Acceptance:** a known mismatch is diagnosable without leaving the list; excluded fields are not shown as mismatches; inspect/copy do not alter counters or dispatch callbacks; callback Resend preserves original evidence.

### P1 — Environments

- [ ] **V01** Migrate environment list/detail with separate Active, Default and Selected concepts.
- [ ] **V02** Preserve create, rename, duplicate, make-default and guarded delete/replacement.
- [ ] **V03** Migrate variable rows/new-variable entry, per-row Save/Delete and dirty/error feedback.
- [ ] **V04** Preserve masked write-only secrets and leave-blank-to-retain behavior.
- [ ] **V05** Preserve variable drafts across selection and explain global activation consequences.

**Acceptance:** editing a selected environment does not switch the global active environment; duplication/deletion keep existing semantics; values never leak through new UI attributes or persistence.

### P1 — Import/export

- [ ] **I01** Migrate real export selection/options, filters, scope, filename and selected counts.
- [ ] **I02** Preserve redaction preview, sensitive acknowledgement and native 1.5 output.
- [ ] **I03** Migrate Native/Postman upload, size/structure errors, companion Environment and mode-specific options.
- [ ] **I04** Render real per-request action/error/warning plans in a scalable list/inspector.
- [ ] **I05** Retain complete variable candidates, source/precedence/conflict notes and original token annotations.
- [ ] **I06** Preserve explicit variable creation and Exclude/Resolve/per-field choices with their consequences.
- [ ] **I07** Surface actual credential/script/template-conversion/request-body warnings without adding script features.
- [ ] **I08** Preserve preview invalidation, confirmation, atomic apply and response-replacement warnings.
- [ ] **I09** Migrate completion links, grouped version snapshots and accurately bounded Undo.

**Acceptance:** native round trips remain format 1.5; masked exports retain disable/replacement flags; Postman correlation and variable behavior are unchanged; no script executes; no run/runtime data becomes portable.

### P2 — history, documentation, access and recovery

- [ ] **H01** Re-skin endpoint/response History with full comparisons and existing restore behavior.
- [ ] **H02** Migrate run history as read-only, including retained deleted-entity names and no Restore control.
- [ ] **D01** Move Documentation to the reading workspace with existing search/anchors/examples.
- [ ] **D02** Update in-app and Markdown user guidance for the new navigation/action locations without rewriting unrelated feature contracts.
- [ ] **A01** Align sign-in, user menu, local access and theme controls with the new system.
- [ ] **A02** Align 404/419/429/500/503 recovery screens; test auth/session failures on dirty forms.
- [ ] **A03** Complete empty/loading/unavailable states across every route and nested editor.
- [ ] **A04** Complete keyboard, touch, real 400% zoom, forced-colors, reduced-motion and screen-reader checks.

**Acceptance:** every route and shared overlay looks like the same application; no page falls back to the former website shell; read-only run constraints, authentication and error semantics remain intact.


## Feature parity inventory

The following target mapping is carried forward from the approved plan after reconciling recent master. It is an inventory of preserved features and intended destinations, **not** a claim that each proposed screen has shipped. The checklist above is the completion authority. **17 of 72 items are verified complete; 55 remain unchecked.**


**Coverage labels:** Shown = present in the prototype; Partial = a simplified illustration exists; Missing = a production design must be supplied. All proposed routes below retain their current URL unless explicitly stated otherwise.

| Existing destination or capability | Current entry point | Prototype coverage | Proposed location / treatment |
|---|---|---|---|
| Endpoint registry | `/dashboard`; `EndpointIndex` | Partial: explorer only | Full-width continuous registry with filters, bulk actions and an optional context sidebar. |
| Endpoint create | `/dashboard/endpoints/create`; `EndpointForm` | Partial: local dialog | Same workbench as edit, with a deliberate unsaved/new state and first-save guidance. |
| Endpoint edit | `/dashboard/endpoints/{endpoint}/edit` | Shown | Explorer plus Editor / Callback / Settings; stable object header. |
| Request parsing and warnings | `EndpointForm` request panel | Partial | Import cURL dialog, compact source summary, parse state and real warnings in request pane. |
| Original cURL and parsed data | Request panel | Partial | Source disclosure and Headers / Query / Body inspection; preserve literal data and copy behavior. |
| Coarse matching policies | Matching panel | Shown | Request pane Matching rules section; current defaults and flags unchanged. |
| Per-field exclusion + original Postman tokens | Matching tables / metadata | Missing | Header/query rows show Included / Excluded and token provenance. |
| Path segment editor | Request panel | Partial: text pattern | Reuse current segment controls within Matching rules; avoid a second pattern editor. |
| Canonical request, variant and SHA-256 | Signature sidebar | Partial | Dedicated inspectable signature section with full canonical content and copy commands. |
| Duplicate signature + credential warnings | Request panel | Missing | Blocking issue or review callout near request source, with existing repair actions. |
| Endpoint name, enabled, priority | Request/header | Shown | Inline title; enabled state in object toolbar; name/priority in Settings. |
| Collection and tags | Request organization fields; registry filters/bulk | Shown/Partial | Flat collection explorer, Settings assignment, preserved filters and bulk operations. |
| Endpoint environment availability | Request organization | Partial | Settings matrix with Inherit / Allow / Disabled for every real environment. |
| Static response create/edit/delete | `ResponseManager` | Shown/Partial | Right pane response selector/list, status/headers/body/delay/weight, explicit commands. |
| Multi-response drafts and global save | `ResponseManager` + `EndpointForm::saveAll` | Partial | Shared save state with per-scope dirty/error markers and response identity retained. |
| Weighted selection | Response selection section | Partial | Selection mode control and weight/probability summary. |
| Sequence order / exhaustion / reset / preview | Response selection section | Partial | Inline sequence list, exhaustion select, read-only next-response preview, scoped Reset. |
| Rule conditions / priorities / one default | Response selection section | Partial | Expandable full-width selection area, ordered responses and original condition grammar. |
| Static vs Template | Response form | Partial | Mode selector; preserve raw text and template mode behavior. |
| Template JSON + schema builder | `response-manager`, `partials/schema-row` | Missing builder | Reuse existing Builder / JSON control, nested rows and method catalog in right pane. |
| Locale, random/fixed/signature seeds, regeneration | Response form | Missing/Partial | Template toolbar and preview controls, retaining all three seed modes. |
| Template issues, quick fixes, sample context | Template preview service | Partial | Resizable/stacked sample panel with issues, location and existing structured codes. |
| Fault injection | Response form advanced disclosure | Partial | Summary + configuration for `delay`, `malformed_body`, `truncated_body`, `timeout` only. |
| Per-response callback configuration | Callback tab | Partial | Response selector, target/payload editor and timing/retries/signing sections. |
| Test saved callback | Callback settings partial | Missing real delivery | Existing command with synthetic-context warning and saved/draft distinction. |
| Request log | `/dashboard/logs?type=requests`; `LogViewer` | Shown snapshot | Live request table and real-detail inspector; retain existing diagnostics. |
| Callback attempts + resend | `/dashboard/logs?type=callbacks`; `CallbackLog` | Missing real records | Callback table + attempt inspector, recorded target, lineage and explicit Resend. |
| Request/callback aliases | `/dashboard/requests`, `/dashboard/callbacks` | Missing | Preserve current routing/alias behavior; no new divergent log page. |
| Embedded recent requests on registry | `LogViewer` compact mode | Missing | Compact optional bottom/activity region or secondary section, not a competing dashboard of cards. |
| Native export | `/dashboard/config`; `ConfigTransfer` | Shown demo | Endpoint selection + export options/review; real native 1.5 output. |
| Native import and replace-pool options | Same | Partial | Source/options + real preview + clear destructive confirmation. |
| Postman import, variables, warnings | Same | Partial | Full variable review and per-request plan, built from existing mapped preview. |
| Import completion / grouped versions / undo | Same | Missing | Persistent completion summary, affected items and bounded Undo this import. |
| Environments / variables / secret editing | `/dashboard/environments`; `EnvironmentManager` | Missing | Environment list plus variable editor with independent save scopes. |
| Active environment | `EnvironmentSwitcher` | Partial single option | Persist existing global selector with explicit instance-wide consequence. |
| Endpoint/response version history and restore | `RevisionHistory` dialogs | Partial local history | Timeline + structural comparison using existing component and restore contract. |
| MCP test-run history | `/dashboard/test-runs/{publicId}/history` | Missing | Read-only run-scoped history in the new shell; preserve no-restore rule. |
| Documentation | `/dashboard/docs` | Partial modal | Reading workspace with section navigation, search, contextual deep links and examples. |
| Sign in / sign out / local access | `/dashboard/login`, user menu | Missing | Quiet product-style sign-in and consistent session/local-access identity. |
| Light / Dark / System | Theme control | Missing light/system | One coordinated design system, persisted with existing theme resolver. |
| Shared confirmations / menus / toasts / tooltips | Existing shared components/scripts | Partial | Re-skin existing patterns; preserve timing, focus, keyboard and safe-action contracts. |
| 404 / 419 / 429 / 500 / 503 | `resources/views/errors/` | Missing | Compact operational recovery states with useful next steps. |
| MCP/verification credentials and tools | CLI/API plus docs | Missing UI | Keep existing CLI/API surface and help. A management console is a separate feature, not required here. |


## Verification ledger

### Foundation / explorer / registry presentation — 28 October 2026

Implementation: persistent topbar + navigation rail + context sidebar + status bar; full-width operating surfaces; warm semantic tokens in both themes; bounded 15-row explorer reusing EndpointIndex's existing query; continuous registry surfaces; sidebar preference; reversible single-character-shortcut opt-out; hoverable/dismissible 24px help triggers. Four existing editor tabs and all server mutation/validation/save-result contracts remain unchanged.

The independent finish review found three defects (320px header collision, missing shortcut-preference recovery, and cancelled tooltip close). All three were fixed together. Browser assertions now check header rectangles, the visible footer Shortcuts opener, ordinary pointer departure and hover-only Escape, rather than relying on document width or programmatic dialog opening alone.

| Checklist scope | First verification | Second verification | Status |
|---|---|---|---|
| F01 direction / registry retention / migration boundaries | Reconciled version-6 prototype and uploaded-master changes; target inventory retained | Independent rendered review confirmed bounded workbench scope and truthful four-tab editor | Verified twice; independent fix verdict: resolved / ship |
| F02 tokens / shared geometry | Design-token validator and exact light/dark reference test pass | Rendered light/dark routes, compact controls and 320/390/1024px layouts inspected | Verified twice |
| F03 source inventory / parity ledger | Original full feature mapping reconciled against 40 modified +19 added master files | Mapping checked against current route/component/service paths and full PHP regression | Verified twice |
| F05 shell / persistence / local-session identity | WorkbenchShellTest verifies all destinations and state controls | Workbench smoke + existing layout smoke verify real Livewire header persistence in both themes | Verified twice |
| F06 scroll / responsive / focus geometry | Existing measured viewport/action-bar logic retained; narrow header fix reviewed | Browser checks reflow, action/title rectangles, sidebar Escape focus and focused-input/action/status-bar clearance | Verified twice |
| F08 dirty-navigation guard | Existing editor-drafts Node tests and native PHP/editor characterization pass | Real browser edits request then attempts rail navigation, cancels discard and retains editor; editor regression tests cover dirty N navigation | Verified twice |
| R01 continuous registry rows | Source uses existing row semantics/actions with one continuous surface | Registry browser regression verifies aligned retained fields at ten widths in both themes | Verified twice |
| R05 bounded explorer / flat collections | Livewire test verifies 15-item pagination, search, current identity, ordinary/run wall and absence of request credentials | Browser exercises explorer search/empty result and the route/sidebar presentation in both themes | Verified twice |

### Actual executed checks

- Native PHP 8.3.6 / Laravel 13.32.0 / Livewire 4.4.5, dedicated external SQLite database for browser fixtures, in-memory SQLite for PHPUnit. No operator database or application .env was changed.
- Full PHPUnit: **439 passed, 4403 assertions, zero skipped**, including native concurrent tests after enabling the local runtime's POSIX extension. Docker/PostgreSQL parity is not implied.
- Node frontend: **84 passed, zero failures**; setup helpers: **16 passed**; design-token validation passed; whole-project Pint `--test` passed.
- `make browser-test`: new workbench smoke, existing layout smoke and documentation smoke passed in both themes. Chromium **150.0.7871.100**, Playwright **1.62.1**, Node **24.22.1**.
- `make browser-regression-test`: existing critique and endpoint-editor regressions passed on the disposable instance. This Make target runs those two scripts, not every script in tests/Browser.
- `endpoint-registry-smoke.mjs`: both themes passed at 1680/1440/1200/1024/901/900/768/740/390/320px with 0–1 MiB bodies, 0–128 headers and 1–101 responses. Its only script change is support for the existing optional Chromium executable convention; no assertions were weakened.
- `git diff --check`, JavaScript syntax and source paths checked. Current screenshot/log artifacts remain outside tracked source.

Independent finish-review verdict: **ship this bounded milestone**. All three material findings were scored resolved after the correction batch, source/test checks and recaptured light/dark 320/390px evidence. This does not approve the remaining unchecked upgrade work.

### BLOCKED / not claimed

- `make validate` was actually attempted and stops at Compose configuration: `make: docker: No such file or directory` (`compose-config`, error 127; Make exits 2). Its independently runnable pieces above were run; the Docker image/Composer/container checks did not pass by implication.
- Docker/PostgreSQL browser/global-save/sensitive-data guarded scripts require their real disposable PostgreSQL setup and were not substituted with SQLite. Existing PHP tests cover those behaviors, but that is different evidence.
- Remote GitHub Actions execution was not triggered/verified here; the new smoke is wired through the existing make browser-test job.
- Real 400% desktop browser zoom, screen readers, physical touch devices and a complete axe audit remain outstanding. No accessibility-conformance claim is made.
- Editor / Callback / Settings consolidation, side-by-side request/response authoring, advanced response/selection/callback migration, Logs inspectors and complete import/environment/history screen migration remain unchecked. First-run readiness and existing per-scope save state are retained, not reimplemented.

### Remaining manual verification

On a disposable Docker/PostgreSQL instance, run make validate and guarded browser scripts in both themes; check logged-in expiry and 419 paths; verify real browser zoom, screen-reader announcements, touch/menu behavior and the remaining planned advanced workflows. Inspect the first foundation milestone before starting the separate endpoint-pane migration.


### Files changed in this milestone

Compared with the imported master snapshot, these files are changed or new. Earlier user developments remain part of the baseline, not attributed to this UI work.

- `.impeccable/design.json`
- `DESIGN.md`
- `Makefile`
- `README.md`
- `app/Livewire/Admin/EndpointIndex.php`
- `docs/ARCHITECTURE.md`
- `docs/UI_GUIDE.md`
- `docs/UI_UPGRADE.md`
- `docs/USER_GUIDE.md`
- `docs/VALIDATION.md`
- `public/css/app.css`
- `public/css/tokens.css`
- `public/js/panels.js`
- `public/js/workbench.js`
- `resources/views/admin/docs.blade.php`
- `resources/views/admin/endpoints/index.blade.php`
- `resources/views/components/workbench-icon.blade.php`
- `resources/views/components/workbench-navigation.blade.php`
- `resources/views/layouts/dashboard.blade.php`
- `resources/views/livewire/admin/endpoint-explorer.blade.php`
- `resources/views/livewire/admin/endpoint-index.blade.php`
- `tests/Browser/endpoint-registry-smoke.mjs`
- `tests/Browser/workbench-smoke.mjs`
- `tests/Feature/WorkbenchShellTest.php`
- `tests/Frontend/workbench.test.mjs`

No Composer/package dependency, route, database migration, canonicalization/matching service or response/callback mutation service was changed.


### Registry interactions and secondary activity — 29 October 2026

Baseline for this increment: the delivered 28 October foundation on uploaded master `8622ec7`; local Git ancestry is still `44a00e30cac673decf93dae3b503abc9c35c5e3d`. User master changes and foundation work remain intact. This increment changes registry presentation/draft synchronization only; it adds no backend service, dependency, route, migration, matching/signature rule or native-format change.

Implemented: full-width search; visible filter labels; separate page-selection/result strip; native mixed checkbox state; a contextual bulk strip stating total/on-page selection and cross-page persistence; existing actions with pending guards; visible row actions; wrapping identities; distinct database/collection/filtered empty states; and initially open, collapsible recent requests using the same LogViewer. Filters use two columns at <=740px and one at <=440px so current native option labels remain readable. R06 remains partial: deleted-current-editor recovery is still pending; empty/loading presentation alone is not its whole requirement.

Runtime testing exposed two actual registry issues, fixed within this scope: the master checkbox could keep its previous page's checked state after morphing, and deferred bulk-tag drafts left Apply tags disabled. The master now synchronizes native checked/indeterminate properties. Bulk tags use live draft binding and preserve the native menu's open state through `wire:ignore.self`; assignments still persist only on Apply. Overflow menus use the same open-state convention. The browser test reads saved state independently and waits for the toggle response to settle before opening a row action.

The existing workbench smoke also assumed the cURL textarea remained expanded throughout the discard dialog. Parsing can legitimately collapse it into the source summary. The test now reopens Edit request when needed and additionally verifies that cancelled navigation retained the exact edited cURL before restoring the baseline. No dirty-navigation application code changed.

| Scope | First verification | Second verification | Completion |
|---|---|---|---|
| R02 filters / sorting / counts / pagination | RegistryWorkspaceTest verifies combined filters, reset, page reset, totals and sort | Real browser exercises method/state/collection/tag filters, totals, name/priority sort and page navigation in both themes | Verified twice; bounded review ship |
| R03 row actions | PHPUnit verifies enable, disabled duplicate with response copy and deletion; existing copy/redaction regression retained | Real browser copies to clipboard, toggles, duplicates, cancels/confirms delete and opens Edit in both themes | Verified twice; bounded review ship |
| R04 selection / every bulk action | PHPUnit checks two-page selection, hidden selection, move/tags/enable/disable/export/delete and clearing contracts | Real browser checks mixed native state, cross-page/filter selection, real persisted action results and native 1.5 download | Verified twice; bounded review ship |
| R07 secondary activity | Source/HTTP test confirms same compact LogViewer in existing disclosure | Browser collapses/opens with mouse/keyboard and follows full-log link in both themes | Verified twice; bounded review ship |

Independent review found clipped selected filter labels at 320/390px and an overbroad test completion message. The correction stacks filters below 440px, checks measured selected-text fit, updates guidance and distinguishes desktop action testing from five-width selected-state geometry. The reviewer scored both findings resolved and returned **ship for the bounded registry workspace milestone** after inspecting all ten current recaptures and corrective source. This is not approval of the remaining editor/screen migration or a full accessibility audit.

Testing uses PHP 8.3.6, Laravel 13.32.0, Livewire 4.4.5, Chromium 150.0.7871.100, Playwright 1.62.1 and Node 24.22.1. Browser databases are separate disposable SQLite files outside tracked source; PHPUnit uses in-memory SQLite. No operator database or application .env was changed. The local large Chromium executable needed restoration from its already downloaded package before launch; runtime setup is outside source, and failed setup attempts are not counted as browser passes.

**BLOCKED:** `make validate` was attempted and stops at `docker compose config --quiet`: `make: docker: No such file or directory` (compose-config error127, Make exit2). Docker image/Composer/container/PostgreSQL checks and remote GitHub Actions are not claimed passed. The Impeccable context/detector launchers both returned Permission denied; source context and independent review were used, not a claimed detector pass. Screen readers, physical touch and real400% zoom remain outstanding.

The new `make browser-registry-test` requires MOCKDECK_BROWSER_FIXTURE=1 and a fresh disposable database; its fixture refuses unrelated endpoints and removes only its own examples. CI invokes it before the other fixture-writing browser scripts. Wiring is verified from workflow source; no remote CI result is implied.


Files changed in the 29 October registry increment (relative to the delivered foundation; earlier imported master changes are not attributed to this increment):

- `.github/workflows/ci.yml`
- `DESIGN.md`
- `Makefile`
- `README.md`
- `docs/UI_GUIDE.md`
- `docs/UI_UPGRADE.md`
- `docs/USER_GUIDE.md`
- `docs/VALIDATION.md`
- `public/css/app.css`
- `resources/views/admin/docs.blade.php`
- `resources/views/admin/endpoints/index.blade.php`
- `resources/views/components/workbench-icon.blade.php`
- `resources/views/livewire/admin/endpoint-index.blade.php`
- `tests/Browser/registry-workspace-smoke.mjs`
- `tests/Browser/workbench-smoke.mjs`
- `tests/Feature/RegistryWorkspaceTest.php`
- `tests/Frontend/filter-layout.test.mjs`
- `tests/Support/registry-workspace-fixture.php`

Design documentation was independently reconciled after the correction batch: DESIGN.md reflects the actual registry geometry and interactions; theme values/frontmatter stay unchanged. The system sidecar remains a valid foundation reference, not a claim to preview every registry state.


Final executed checks for the registry increment:

| Check | Actual result |
|---|---|
| Full configured PHPUnit suite | PASS:443 tests,4445 assertions,zero skipped (42.50s final run). PostgreSQL-only guarded suite remains BLOCKED below. |
| Frontend Node suite | PASS:84 tests,zero failures. The prior hidden-caption assertion was deliberately updated to require visible registry captions; Log filters retain their existing compact contract. |
| Setup helpers / tokens / Pint | PASS:16 setup tests; design-token literal and paired-theme contrast validation; whole-project Pint --test. |
| New make browser-registry-test | PASS:desktop workflows in both themes; selected-state geometry1440/1024/740/390/320; selected option text fit320/390. Actions are not claimed exercised at all five widths. |
| make browser-test | PASS:workbench, layout and documentation scripts, both themes; dirty source retained after cancelled navigation, including collapsed-source case. |
| make browser-regression-test | PASS:critique and endpoint-editor regression scripts, both themes, on a disposable instance. |
| Existing registry alignment smoke | PASS:both themes; ten widths;0–1MiB request bodies,0–128 headers and1–101 responses. |
| CI wiring / source hygiene | PASS:YAML parses; fresh-registry target precedes other fixture writers; JS syntax and git diff --check pass. Remote CI is not claimed run. |
| Independent finish / design documentation | PASS at bounded registry scope:both material findings resolved; all ten current recaptures reviewed; actual anatomy documented without changing theme tokens. |
| make validate / Docker / PostgreSQL | BLOCKED:docker executable unavailable; no container validation or guarded PostgreSQL browser result claimed. |

Remaining manual work: Docker/PostgreSQL validation and guarded suites; actual400% browser zoom, screen-reader announcements and physical touch; deleted-current-editor recovery (R06). At the close of this registry milestone, the next feature milestone was the endpoint workbench migration. Historical completion at that point:12/72;60 pending. The current boundary/count above incorporates the subsequent editor ledger.


### Endpoint workspace — 29 October 2026

This increment builds on the delivered registry workspace; uploaded master `8622ec7` is preserved and local Git ancestry remains `44a00e30cac673decf93dae3b503abc9c35c5e3d`. The source inventory was compared with a 500-file before-editor SHA-256 manifest outside the repository. The list below attributes only this increment, rather than treating imported master changes as UI work.

Implemented: Editor / Callback / Settings task navigation; a left request/matching/signature pane and right Responses pane on wide desktops; one stacked scroll owner at <=1100px; response form before the configured pool; a saved-response picker and New response using existing draft methods; metadata in Settings with its original endpoint form/save boundary; legacy deep links; Copy mock curl / Logs beside tasks; source URL versus saved invocation copy; metadata validation routed to Settings; existing callback selection and draft handling. `CopiesMockCurl` extracts the former registry action unchanged for both callers, resolving only saved requests and current Environment values on the server when explicitly copied. No route, migration, dependency, canonicalization, matcher, native format or mutation service changed.

The title retains the existing inline rename boundary. C06/C07 therefore remain partial rather than claiming every proposed Settings rearrangement is complete. Existing advanced template/selection/fault/callback controls remain reachable through their existing forms; their full design migrations are not checked off by this milestone. E03's source-import dialog, advanced response/callback design and later feature work remain pending.

| Checked scope | First verification | Second verification | Result |
|---|---|---|---|
| E01 task/pane migration + compatibility | EndpointWorkspaceTest covers seven deep-link cases, one manager and sibling forms; configuration/signature/native export remain unchanged by navigation | Real browser exercises legacy/new links, tasks/subtabs, keyboard and one mounted manager in both themes | PASS at this bounded scope |
| E02 first-save / serving readiness | FirstRunClarityTest and existing invocation characterization retain diagnostic/no-match contracts | Real browser creates request, calls with no response (existing diagnostic500), saves literal response and receives201 | PASS |
| E04 source versus invocation | Shared copy action test verifies saved request, action-time Environment resolution, ignored unsaved source and no injected secret attribute | Real browser copies actual mock invocation through existing delegated clipboard handler; source/signature visibly distinguished | PASS |
| E09 drafts / global save integration | EndpointWorkspaceTest, GlobalSaveResultsTest and existing save/draft suites verify scoped preflight, error destination, preservation and reconciliation | Real browser invalid weight blocks save; corrected global save independently re-reads persisted response bodies, callback, priority, collection/tag; dirty navigation cancellation retains state | PASS locally; PostgreSQL guarded audit BLOCKED |
| S01 response identity and drafts | Direct Livewire selector/createNew tests preserve other saved-response and new-response drafts | Real browser switches responses and tasks, revisits new draft and checks selected callback identity in both themes | PASS |

All other unchecked editor/response/callback items remain pending even where the original feature still functions. Current completion: **17/72**, **55 unchecked**.

Environment: PHP8.3.6, Laravel13.32.0, Livewire4.4.5, Chromium150.0.7871.100, Playwright1.62.1, Node24.22.1. Real Laravel was served through native PHP against an external disposable SQLite file; PHPUnit uses in-memory SQLite. No operator database or application .env was changed.

| Executed check | Actual result |
|---|---|
| Full configured PHPUnit suite | PASS:454 tests,4498 assertions,zero skipped (46.00s). Guarded PostgreSQL-only characterization remains outside this local configured suite. |
| Node frontend / setup | PASS:84 frontend tests;16 setup tests. |
| Tokens / Pint / source hygiene | PASS:paired-theme contrast and forbidden-literal validator; whole-project Pint --test; changed-JavaScript syntax; git diff --check. |
| make browser-editor-test | PASS:both themes, real create/call/save, drafts, clipboard, validation, successful global save + independent DB reads, keyboard, legacy URLs; geometry/screenshots1440/1024/390/320. |
| make browser-test | PASS:workbench/layout/documentation scripts in both themes. |
| make browser-regression-test | PASS:critique and endpoint-editor scripts completed with exit0 in both themes. The earlier timing-assumption failure is recorded below, not counted as a pass. |
| CI wiring | Source confirms browser-editor-test after registry fixture smoke; no remote CI run claimed. |
| make validate | BLOCKED:stops at docker compose config --quiet; make:docker:No such file or directory, compose-config error127, Make exit2. Container build/composer/PostgreSQL checks not run. |
| Impeccable detector | BLOCKED:launcher Permission denied,exit126. Source context and independent finish review substitute for neither a detector pass nor a complete accessibility audit. |

The final editor smoke was repeated after the navigation helper change and passed in both themes (exit0). One repeat first tried to focus the Request subtab immediately after Home changed the main selected tab, before Alpine displayed the subtab. The test now waits for the visible Request subtab and asserts actual focus before sending ArrowRight; the keyboard expectation itself is unchanged. This transient test failure is not counted as a passing run.

An initial older critique browser assertion read Settings immediately after clicking its task tab. The shared browser helper now waits for the visible destination panel before assertions. This changes test synchronization only; the original visibility, draft and validation assertions remain. Other older scripts were adjusted to operate the new visible navigation through this helper rather than removed. Scripts not executed in this milestone are not claimed green merely because their syntax passes.

Remaining manual/release checks: Docker/PostgreSQL make validate and guarded characterization/global-save/sensitive-output browser suites; remote GitHub Actions; real400% browser zoom, screen-reader announcements, physical touch and forced-colors. Responsive captures are geometry evidence, not a substitute for those checks. Full redesigned advanced response/callback forms are next; no whole-application redesign completion is claimed.

Independent finish review returned **ship for the bounded endpoint workspace milestone**, after inspecting14 actual captures spanning both themes, all four widths and Request/Matching/Settings/Callback/validation states plus implementation source. No material finding was raised within that scope. This is not approval of the advanced response/callback redesign or a claim of assistive-technology verification. DESIGN.md and its sidecar were reconciled separately without changing theme values.

Files changed in this endpoint increment (relative to its500-file before-editor manifest; imported master and earlier UI work remain intact):

- `.github/workflows/ci.yml`
- `.impeccable/design.json`
- `DESIGN.md`
- `Makefile`
- `README.md`
- `app/Livewire/Admin/Concerns/CopiesMockCurl.php`
- `app/Livewire/Admin/EndpointForm.php`
- `app/Livewire/Admin/EndpointIndex.php`
- `docs/ARCHITECTURE.md`
- `docs/UI_GUIDE.md`
- `docs/UI_UPGRADE.md`
- `docs/USER_GUIDE.md`
- `docs/VALIDATION.md`
- `public/css/app.css`
- `resources/views/admin/docs.blade.php`
- `resources/views/layouts/dashboard.blade.php`
- `resources/views/livewire/admin/endpoint-form.blade.php`
- `resources/views/livewire/admin/partials/callback-settings.blade.php`
- `resources/views/livewire/admin/response-manager.blade.php`
- `tests/Browser/critique-regressions.mjs`
- `tests/Browser/dialog-smoke.mjs`
- `tests/Browser/editor-consistency-smoke.mjs`
- `tests/Browser/editor-navigation.mjs`
- `tests/Browser/endpoint-editor-regressions.mjs`
- `tests/Browser/endpoint-registry-smoke.mjs`
- `tests/Browser/endpoint-tabs-smoke.mjs`
- `tests/Browser/endpoint-workspace-smoke.mjs`
- `tests/Browser/first-run-smoke.mjs`
- `tests/Browser/global-save-results.mjs`
- `tests/Browser/honest-labels-smoke.mjs`
- `tests/Browser/response-save-smoke.mjs`
- `tests/Browser/sensitive-data-smoke.mjs`
- `tests/Browser/ui-guide-audit.mjs`
- `tests/Browser/wave1-smoke.mjs`
- `tests/Browser/wave2-smoke.mjs`
- `tests/Browser/workbench-smoke.mjs`
- `tests/Feature/EndpointTabsTest.php`
- `tests/Feature/EndpointWorkspaceTest.php`
- `tests/Feature/GlobalSaveResultsTest.php`
- `tests/Frontend/editor-recovery.test.mjs`

No Git commit was created or staged by this task. Release archives exclude local environment values, dependencies, databases, screenshots and runtime logs.
