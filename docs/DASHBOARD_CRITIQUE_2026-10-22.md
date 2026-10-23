Method: dual-agent (A: /root/critique_design · B: /root/critique_evidence)

# MockDeck dashboard critique — 22 October 2026

Reviewed repository revision: 3942fca. Application source was not changed.

MockDeck has a coherent identity and a useful operational model. Method/path pairs, matching exclusions, canonical previews, response pools and request-linked logs make it feel designed for API testing. Preserve the warm neutral/ochre palette, semantic status colors, native controls and monospace exact data. The work needed is interaction reliability, recovery information and task order, rather than a replacement visual design.

**Combined design health: 25/40 — Acceptable; focused improvements needed.** Assessment A independently scored 27/40 before Assessment B's mobile clipping and silent-validation evidence entered synthesis. The combined score lowers system status and consistency by one point each. This is a heuristic review, not an accessibility certification.

| Heuristic | Score /4 | Main reason |
|---|---:|---|
| Visibility of system status | 2 | Invalid variable edits can fail without visible feedback; selection draft wording is always shown. |
| Match between system and real world | 3 | API terminology is appropriate; the required curl input follows optional setup decisions. |
| User control and freedom | 2 | Environment switching discards unsaved edits without a warning. |
| Consistency and standards | 2 | Enter overrides nested native controls; autocomplete state and mobile containment diverge from the shared recipes. |
| Error prevention | 3 | Safe import defaults, confirmations, redaction and duplicate checks are strong. |
| Recognition rather than recall | 2 | Same-status responses/callbacks hide their distinguishing identity. |
| Flexibility and efficiency | 3 | Shortcuts, filters, bulk actions and move alternatives support expert use. |
| Aesthetic and minimalist design | 3 | Cohesive styling; History composition and creation hierarchy need correction. |
| Error diagnosis and recovery | 2 | Callback history omits failure causes and can show the wrong destination. |
| Help and documentation | 3 | Extensive task examples; long documentation lacks local search/persistent navigation. |
| **Total** | **25/40** | **Acceptable** |

## What works

- Matching is inspectable: policy, exclusions and canonical request output explain why a request should match.
- Import and template previews establish trust: explicit actions/counts, overwrite warnings, secret masking, synthetic-sample labels and recovery options are valuable.
- The component vocabulary is cohesive across themes. Existing labels, native selects, keyboard tab navigation, modal focus return and reduced-motion support are strengths to preserve.

## Five priority groups

### 1. [P1] Mobile Environment actions are outside the usable screen

At 390px, the editor container is about 347px wide but its child cards measure about 496px. Duplicate, Save name and variable Save/Delete/Add sit beyond the right edge. The table wrapper grows with the content instead of becoming a usable internal scroller. A root-page overflow assertion passes because the excess is clipped; that does not make the controls accessible.

**Fix:** contain the grid's intrinsic width with shrinking tracks and min-width:0 on its children; wrap the heading/actions on mobile; constrain the table's own scroller. Verify every button is visible or reachable by a real internal scroll at 390px.

**Evidence:** public/css/app.css around 396–409 and 912–913; resources/views/livewire/admin/environment-manager.blade.php. Live measured bounds and screenshots confirmed it.

**Suggested command:** `$impeccable adapt`.

### 2. [P1] Environment editing rejects saves silently and loses drafts

Two distinct defects damage confidence in the same workflow:

- Changing saved key UI_AUDIT to invalid 1bad and pressing Save leaves persisted data unchanged, with no field error, alert or toast. The validator returns key/value paths while Blade listens for variableDrafts.<id>.key/value.
- Editing a name or variable, selecting another Environment and returning discards the draft without confirmation.

**Fix:** map validation errors to the actual row paths, associate errors with the input, announce/focus the error, and preserve drafts per Environment or guard leaving dirty state with Save/Discard/Keep editing. Do not add warnings when no edits exist.

**Evidence:** app/Livewire/Admin/EnvironmentManager.php around 40–52 and 157–168; resources/views/livewire/admin/environment-manager.blade.php around 72–74. Both behaviors were reproduced; persisted API data was checked for the invalid-save case.

**Suggested command:** `$impeccable harden`, followed by `$impeccable audit`.

### 3. [P1] History is almost unreadable at desktop width

At 1440px the endpoint History dialog is only 440px wide. Action buttons consume the auto-sized column, leaving version text and the change summary about 30px wide; words wrap into vertical fragments. This remained after animations settled.

The intended 900px .history-dialog rule is overridden by the later, equally specific .ui-dialog width rule. The revision layout's responsive behavior follows viewport size rather than the narrow dialog's actual width.

**Fix:** give History a deliberate wide dialog variant and make revision actions wrap/stack before the reading column collapses. Verify endpoint/response history and comparison views at desktop, mobile and zoom.

**Evidence:** public/css/app.css around 254–274, 421 and 855; resources/views/livewire/admin/revision-history.blade.php. Stable live screenshot confirmed it.

**Suggested command:** `$impeccable layout`, then `$impeccable adapt`.

### 4. [P1] Enter on Copy opens Edit

Focusing an endpoint's Copy mock curl button and pressing Enter navigates to the endpoint editor. The row-level Enter handler intercepts descendants; its click counterpart correctly excludes interactive controls.

**Fix:** activate row navigation only when the row itself is the keyboard target, or apply the same interactive-target exclusion as the click handler. Verify Copy, overflow, checkboxes and disclosures separately.

**Evidence:** resources/views/layouts/dashboard.blade.php around 362–373. Live URL changed to /dashboard/endpoints/4/edit after Enter on Copy.

**Suggested command:** `$impeccable harden` / `$impeccable audit`.

### 5. [P1] Callback logs can misstate historical evidence

A failed attempt recorded old-target.example.test, but its log row displayed receiver.example.test, the response's current configured URL. The stored error explaining HTTP 500 was also absent. The visible actions do not provide enough detail to diagnose the failed attempt before resending it.

**Fix:** show a safely masked recorded target_url, not the mutable current configuration. Add an accessible attempt-details disclosure with the sanitized error, request/response identity, retry lineage and clear resend semantics.

**Evidence:** resources/views/livewire/admin/callback-log.blade.php around 43–54; app/Models/CallbackAttempt.php. Both reviewers confirmed the deliberately different recorded/current targets.

**Suggested command:** `$impeccable harden`, then `$impeccable clarify`.

No product-wide P0 blocker was established. These five groups contain six independently confirmed P1 defects, because Environment editing has separate validation and draft-loss failures.

## Page/tab coverage

Every dashboard route/component family and nested source branch was examined. “Live” means current rendered UI; it does not mean every possible data value or destructive action was executed.

| Area | Items/tabs checked | Result |
|---|---|---|
| Shared shell | Desktop/mobile navigation; Theme, Environment, Local menus; footer; shortcuts; skip link | Coherent system; correct theme variants. Keyboard row interception is separate from working tab navigation. |
| Endpoint registry | Row facts, canonical preview, Copy/Edit/toggle/overflow, tags/collections, no-response warning | Copy Enter defect; repeated no-response warnings in the fixture are intentional truth. |
| Registry controls | Search, method/state/sort/collection/tags, clearing, bulk actions, selection, pagination, empty/loading branches | Broad expert controls are justified; minor loading-feedback inconsistency for collection/tag filters. |
| Create / Request | Curl/Paste/Wrap/Example/Clear, parsing, credential mask, name/priority/enabled, path chips, organization, Environment availability, disabled-save reasons | Curl appears after optional decisions and below the initial mobile viewport. Move it first on Create. |
| Edit / Request + Signature | Collapsed/edit request, inline title, path names, canonical output/hash, headers/body, policy removal, Copy, host note | Strong explanation of matching. Keep detailed hashes secondary to the task. |
| Matching | Cookie/auth/all-header policies; per-header/query exclusions; body explanation; hints/errors | Controls and overrides are explained; source branches and live panel checked. |
| Response / Weighted | Pool rows, edit/add/cancel, Details/History, status/headers/body/weight/delay | Same-status responses need visible ID/example label/body summary. |
| Response / Sequence | Order/move/drag alternatives, exhaustion policy, position/environment, reset dialog, preview | Useful separation of runtime cursor and draft order; weight's irrelevance in other modes should be clearer. |
| Response / Rules | Default fallback, condition type/operator/value/priority, add/remove and preview | Functional model is understandable; deep Details→Conditions nesting increases recall. |
| Template / Builder | All seven types; root object/list; nested objects/arrays; Faker search/arguments; bounds, precision/null%, ordering | Consistent shared editor; relative date amount needs visible units. |
| Template / JSON + preview | Autocomplete, errors/quick fixes, locale/seed modes, regeneration, sample notice, metrics | Autocomplete can show a list while aria-expanded=false; lacks option IDs/aria-activedescendant. |
| Fault injection | Enable, Delay/Malformed/Truncated/Timeout, probability/bounds, approximation copy | Shipped fault types clearly described; no new P1 finding. |
| Callback editor | Response selector; target/method/headers/body; Builder/JSON; delay/retries/backoff/timeout; signing; test controls | Reuses editor patterns; response identity needs to be visible across tabs. No outbound test sent. |
| Endpoint/Response History | Timeline, comparisons, restore dialog, response disclosure, empty/diff branches | Desktop dialog squeeze is P1; Escape focus return works. Restore was not accepted. |
| Request logs | Search/filters/grouping/time/density, live/pause, details, request Copy, unmatched/candidate and callback branches | Useful diagnostics; opening detail pauses refresh. Not every unmatched-data permutation was generated. |
| Callback logs | Filters/time/density, status/attempt/duration/lineage, request link, resend and empty states | Historical target/error defect; hidden deep-link scope needs a visible removable chip; mobile status lies far right. |
| Environments/variables | Selection versus Active/Default; create/rename/duplicate/default/delete replacement; normal/secret variables and validation | Mobile clipping, silent invalid-save and draft loss confirmed. Destructive confirmations were not accepted. |
| Export | All/Collection/Environment scope, search/selection, redaction preview, sensitive acknowledgement, selected/all export | Export all and Export a backup first should explicitly name their effective scope/redaction policy. |
| Native import | Upload/file/progress, all modes, response replacement, conflict/warning/error review, confirmation and success/undo branches | Live upsert preview reports Update/snapshots but has no old/new field diff. Apply/undo branches source-reviewed only. |
| Postman import | Format/companion file, modes, candidates/secrets, creation action, exclude/resolve, Environment/per-field choices, warnings/masking | Live light/dark desktop/mobile previews; missing resolution errors correctly disable Confirm import. Separate variable/import confirmations are clear. |
| Documentation | All 25 section families and ToC, examples, setup/HTTPS, operations, MCP, accessibility, shortcuts/license | Long-reference retrieval needs search or persistent local navigation. External links not followed. |
| MCP run History | Label/status, read-only scope, per-mock timeline/compare and empty branch | Correct read-only presentation; summaries should include mock names, not only numeric IDs. |
| Login and errors | Login plus 404/419/429/500/503 shells, theme and recovery copy | Consistent shell. Login/non-404 errors inspected as app-rendered fixture visuals, not induced live auth/outage tests. |

## Remaining improvements

- **P2:** Put the required curl first on Create; progressively reveal organization/runtime settings afterward.
- **P2:** Expose stable response identity in both Response and Callback rows, including imported example labels.
- **P2:** Make draft labels truthful. “Unsaved selection draft” currently appears even without a change; distinguish selection, response, callback and global Save scopes.
- **P2:** Show a compact old/new diff before Native/Postman upsert confirmation, especially response-pool replacement. Snapshot/undo counts do not explain the prospective change.
- **P2:** Synchronize JSON autocomplete's expanded and active-option state through Livewire morphs.
- **P2:** Associate invalid inputs with their visible errors. Visible labels are generally present; globally claiming unlabeled controls would be wrong.
- **P2:** Make Escape actually hide the Priority tooltip until focus leaves; CSS focus styling currently keeps it visible after its popover closes.
- **P2:** Label response/request filters in callback deep links with removable scope chips.
- **P2:** Put callback status and the useful action earlier in compact mobile rows while retaining contained table scrolling.
- **P2:** Show days/years beside relative-date amounts in the Builder.
- **P3:** Add documentation retrieval/search and readable mock names in run History; give 419 an explicit refresh action matching its copy.

## Cognitive load and emotional journey

The problem is task ordering and identity, not the mere number of API options. Seven HTTP methods, seven Builder types, searchable Faker methods and many filters are legitimate for this audience.

The main memory bridges are choosing among indistinguishable response rows, understanding several save scopes, and leaving a failed callback to hunt for missing evidence. First creation has an avoidable valley: “Paste one curl” is promised while secondary controls appear first. Parsing and generated previews are the strongest moments because cause/effect is visible. History and failed-delivery investigation should be the clearest recovery moments, but currently become the weakest.

## Persona checks

- **Alex, power user:** Enter on Copy changes page. Repeated response-detail inspection slows work despite otherwise useful shortcuts, bulk actions and filters.
- **Sam, keyboard/low-vision user:** Nested-control Enter interception, unreadable History wrapping, silent errors and stale autocomplete semantics are material barriers. Native labels, tab arrows, move alternatives and dialog focus return are strengths. No NVDA/VoiceOver speech test was performed.
- **Jordan, first-time operator:** Required curl is buried, perpetual draft wording suggests unfinished work, and same-status callback choices lack identity. Reorder and clarify the existing UI before adding tours.

## Evidence boundaries

Assessment B's mandated markup scan completed once with exit 0 and stdout []. It reported **zero findings**, no rule/file findings or false positives, and no scanned-file count. That does not invalidate behaviorally demonstrated defects. The token validator passed its declared light/dark contrast pairs; it is not proof of every possible rendered contrast combination.

B captured 84 primary states across 1440/1024/390 in both themes, plus 54 supplemental states and focused interactions. There were zero JavaScript page errors, visible fields had labels, tab arrow/Home/End behavior worked, and one tabpanel was visible at a time. Root-page overflow checks passed despite the Environment clipping: containment tests need child/button reachability assertions.

A independently reviewed the source and current rendered states, including all seven Builder types, fault variants, menus and History. Parent supplements covered Native upsert, Postman warnings/variables and blocked missing-value resolution in both themes at desktop/mobile.

Native cloud browser could not reach executor localhost; a local official headless-shell with isolated SQLite fixtures supplied the visual evidence. Unstyled/router failures and the Livewire testing-upload configuration error were corrected only in the temporary harness and excluded from product findings. No external callback, destructive deletion/restore, successful import apply/undo, real authentication failure or outage was performed. Those unexecuted branches are explicitly source-only. No user-visible detector overlay exists.

The standard critique slug/snapshot/trend helper was blocked during an attempted GitHub engine download by automatic approval review. It was not retried. The successful independent detector invocation had already completed before that constraint. This Markdown is the complete review archive; application source remained unchanged.

## Choices for a follow-up

1. Start with **interaction/recovery reliability**, **import/callback clarity**, or **creation/mobile layout**?
2. Fix **the five priority groups only**, **all P1/P2 findings**, or **the complete list including polish**?
