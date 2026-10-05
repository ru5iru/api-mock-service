# Wave 3 — Postman Collection v2.1 import

Implemented against the Wave 2 codebase, including the later endpoint/UI fixes through `6ca6dce`. This update package contains only changed/new files; it excludes dependencies, runtime storage, databases and credentials.

## Delivered

- Postman v2.1 structural validation, with clear blocking errors for legacy/v1 and malformed documents.
- Nested folder traversal and flat ` / ` Collection names, including empty Collections for empty folders.
- Shared URL parsing; authority discarded; whole-segment variables mapped to Wave 2 `{name}` parameters.
- Explicit header/query variable exclusions with per-field preview warnings. Body variables stay literal.
- Inherited auth translation, body-mode handling, non-executing script detection and stale-example warnings.
- Saved examples become static equal-weight responses, with their names retained in `external_label`. Requests without examples retain zero responses.
- Correlation-based Create only, Update matching and Clone, using the existing import page and revision-batch Undo action.
- Shared credential detection/masking, including saved-response Set-Cookie headers and quoted credentials.
- Native **1.5** metadata portability, new checked-in schema, backward-compatible null defaults and revision support.
- Updated operator, import/export, in-app and UI documentation; new `docs/POSTMAN_IMPORT.md`.

The optional companion Environment-file importer was **cut**. It would require another environment create/update and operator-adjustable secret-review workflow. Existing MockDeck Environment management remains available. This does not block importing collections with unresolved variables.

## Auth support as shipped

| Type | Result |
|---|---|
| bearer | Authorization Bearer header; unresolved variables exclude that header |
| basic | Base64 username/password Authorization; variable detection before encoding |
| apikey, header | Configured header/key/value; variable-aware exclusion |
| apikey, query | Configured query/key/value; variable-aware exclusion |
| noauth | No generated auth; stops ancestor inheritance |
| oauth2 | Named warning; no translation |
| digest | Named warning; no translation |
| awsv4 | Named warning; no translation |
| ntlm | Named warning; no translation |
| hawk | Named warning; no translation |
| Any other type | Named warning; no translation |

Explicit header entries remain present even when an unsupported auth block is not translated. Missing auth/events inherit the nearest folder, then collection. Scripts are never executed or translated; only type/line-count warnings enter the mapped document.

## Acceptance verification

All listed acceptance behaviors passed automated regression tests using representative collections:

| Requirement | Evidence |
|---|---|
| Two-level nested folders and empty folders | Flattened Collection names, endpoint counts and no fabricated endpoints asserted |
| Header/query variables | Exact exclusion lists, V6 canonical string and matching against changed live values asserted; warnings visible in browser |
| Malformed/commented JSON body | Original text preserved and canonical raw-text fallback verified; warning visible |
| Script handling | Pre-request/test type and line counts asserted; script text absent from mapped configuration; throwing script did not execute in browser flow |
| Saved examples | Correct status, headers, body, labels, equal weights and Weighted mode asserted |
| No examples | Zero responses retained and existing no-response warning emitted |
| Create only, Update, Clone | Idempotent skip, correlation updates, batch revisions and unconditional clone asserted |
| Undo | Added examples removed, deleted examples recreated, original rules/callback settings restored, sequence order preserved, runtime counters untouched |
| Auth | Bearer/basic/API-key mapping, ancestor inheritance, explicit noauth and untranslated OAuth2 asserted |
| Credential masking | Shared detector drives banner; request and response secrets replaced; quoted credentials covered |
| Native portability | 1.5 round-trip retains source/label; 1.4 import defaults both to null; earlier-version regression tests pass |
| Existing application | Full previous PHP/frontend regression suites pass |

History comparison includes the actual current response pool, and recursively redacts signing-key ciphertext inside that pool. Undo sequence restoration applies saved positions as a group instead of incrementally shifting them.

A valid raw JSON/GraphQL body without Content-Type receives `application/json`, with a visible warning, so it uses the existing JSON canonicalizer. Explicit Content-Type is preserved. Partial path-variable segments remain literal, with escaped literal braces where needed alongside real wildcard segments.

## Validation results

- **PHPUnit: 255 passed, 1,856 assertions**, including 14 Postman-focused tests.
- **`make frontend-test`: 48 passed.**
- **Design-token validation: passed.**
- **Composer validation (`--strict`): passed.**
- **Pint (`--test`): passed.**
- **Compose configuration: passed.**
- **Playwright `tests/Browser/layout-smoke.mjs`: passed in light and dark themes.**
- **Playwright `tests/Browser/postman-import.mjs`: passed in light and dark themes**, including upload, preview, credential masking, expanded warnings, enabled confirmation and successful apply. Responsive checks/screen captures used 1440, 1024, 767 and 390px widths.
- **`make validate` was attempted but did not complete:** Compose, design and frontend stages passed; the image build was blocked by sandbox access to `/var/run/docker.sock` (`socket: operation not permitted`). Composer/Pint/PHPUnit were therefore run directly against the available local PHP runtime. The Docker image build and container-backed full validation must be rerun on your machine; they are not claimed as passed.

Browser verification used an isolated local SQLite instance. No production instance, real imported collection or deployed Docker image was modified/tested.

## Reuse for Wave 4

`ImportMapper` defines an inert format-adapter result. `PostmanMapper` owns Postman-specific parsing and warnings. `MappedImportService` owns previews, correlation analysis, transactions, Collections, response-pool persistence and revision batches. Both use the shared `ImportPlan` and `ImportSummary`; the existing Livewire import page supplies the selector and review UI.

Wave 4 can add an OpenAPI mapper and another option to this same selector. It can reuse `external_source` rather than add another column, and use the same mapped import service/UI. No OpenAPI mapper or Postman export is included in Wave 3.

No new visual primitive was needed. UI_GUIDE records reuse of segmented controls, the dropzone, radios, badges, tables/disclosures, credential banner and existing confirmation dialog.

## Operational boundaries

- Correlation uses folder path, item name, method and mapped path. Renames/moves/path changes create rather than update on reimport.
- Update replaces the response pool with imported examples and resets it to Weighted; local templates/rules/callbacks/faults in that pool are replaced. The pre-state is undoable.
- Imported duplicate signatures/clones are allowed; existing runtime priority/specificity/ID ordering chooses the answering endpoint.
- Duplicate keys within one file use the first entry in Create/Update. If existing clones share a key, Update uses the oldest and warns.
- History-based Undo restores updated entities; it does not delete newly created endpoints or Collections.
- Runtime call state is neither exported nor reset by import/undo. Newly created endpoints have no state until matched.
- Upload/import caps remain shared with native import (2 MiB, 500 endpoints, 5,000 responses by default); import is synchronous within those limits.

## Apply this update

From your MockDeck repository root, overlay the archive's paths, then rebuild and migrate:

```bash
unzip -o /path/to/MockDeck-Postman-Import-Update.zip -d .
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
make validate
make frontend-test
```

The new migration is `2026_10_04_000001_add_external_import_metadata.php`; it adds only nullable provenance/label columns. There are no new Composer/npm dependencies or environment settings.

For browser checks against your local instance (with Playwright installed):

```bash
MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/layout-smoke.mjs
MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/postman-import.mjs
```

The Postman browser test creates disposable example endpoints/Collections, so run it against a test instance.
