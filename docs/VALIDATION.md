# Validation guide

## Local HTTP/HTTPS connectivity

Complete [certificate generation and client trust](HTTPS.md) before enabling the HTTPS Compose override. Compose configuration validation does not verify certificate files or prove a running listener is reachable.

```bash
docker compose config --quiet
docker compose ps -a
curl -fsS http://localhost:18473/up
curl -fsS https://localhost:18473/up
MOCKDECK_SMOKE_ORIGIN=http://localhost:18473 python3 scripts/smoke_http_https.py
```

Both schemes use the same published port when the override is enabled. For a client without system CA trust, use `--cacert /path/to/rootCA.pem` with curl and `MOCKDECK_SMOKE_CA=/path/to/rootCA.pem` with the Python smoke script. That script checks concurrent mixed-protocol health calls, dotfile denial and scheme-correct dashboard assets. Also test a configured mock with each scheme; scheme changes do not alter its signature.

If Nginx restarts or neither scheme connects, inspect `docker compose logs --tail=80 nginx`. A missing certificate stops both listeners; fix the file/mount, run `nginx -t`, and recreate Nginx. For browser authority warnings, trust the issuing CA in the browser's OS. See the [troubleshooting table](HTTPS.md#troubleshoot-connection-and-certificate-failures) for exact commands.

This guide is the release checklist for the fixed MockDeck service. Run it from the repository root.

For documentation UI checks with Playwright available, run `MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/documentation-smoke.mjs` against an accessible dashboard. It verifies the setup sections, disclosure behavior and command wrapping at desktop/mobile sizes in both themes.

## Prerequisites

- Docker Engine with Docker Compose v2
- GNU Make, Python 3, and Node.js 22 (with npm)
- `curl` for the health and smoke checks
- mkcert for optional local HTTPS

The Docker workflow is the canonical path. It pins the runtime through the project image and does not require host PHP or Composer.

## One-time environment setup

Follow [SETUP.md](SETUP.md), then run:

```bash
make setup
# A new .env without certificates selects HTTP; otherwise run make http-enable if needed.
# Read dashboard credentials from .env before starting.
make up
make health
```

Setup creates a missing `.env`, generates missing placeholder credentials, and selects HTTP only for a new `.env` without a complete certificate pair. Existing `.env` settings are preserved: run `make http-enable` if needed for certificate-free HTTP before `make up`. Read credentials from `.env`. For dual HTTP/HTTPS, install mkcert, then run `make https-setup`, `make up`, `make health`, and `make https-check`; see [HTTPS.md](HTTPS.md).

## Complete automated validation

```bash
make validate
```

`make validate` runs, in order:

1. `docker compose config --quiet` and setup-helper regression tests
2. design-token literal and light/dark contrast validation
3. frontend Node tests for theme, preferences, layouts, template editor, and icons
4. a cached rebuild of the app image so validation always uses the current files
5. `composer validate --strict` in the app image
6. `vendor/bin/pint --test` in the app image
7. `php artisan test` against in-memory SQLite in the app image

The validation containers disable entrypoint migrations because an in-memory SQLite database exists only for one PHP process. Database-dependent tests use Laravel's `RefreshDatabase` trait so their schema is created inside the PHPUnit process that executes the assertions.

`EndpointSaveCharacterizationTest` is excluded from the default SQLite suite and runs explicitly in the container CI job on a fresh disposable PostgreSQL database. It is not skipped: its pre-migration guard requires `DB_CONNECTION=pgsql` and asserts that `current_database()` equals the supplied `AUDIT_DB`. On a disposable Compose project only, create a fresh test database, then run `php artisan test tests/Feature/EndpointSaveCharacterizationTest.php` in the app container with `DB_DATABASE` and `AUDIT_DB` set to that database and `RUN_MIGRATIONS=false`. Never point this test at an operator database. Selecting only `--filter` against the default suite does not include this excluded file; pass its path explicitly.

The primary-log sensitive-data test configures a private temporary file channel and storage directory for the real request logger and tailer, then restores configuration and removes that directory. It does not depend on `MOCK_LOG_FILE` being enabled for the suite or read existing instance request logs.

Run individual checks while iterating:

```bash
make compose-config
make design-validate
make frontend-test
make composer-validate
make lint
make test-unit
make test-feature
make test
```

Apply formatting deliberately with:

```bash
make format
```

## Start and inspect the service

```bash
make up
make health
make logs
```

Expected health response: HTTP `200` from `http://localhost:18473/up`.

If the health command returns `502`, nginx is running but PHP-FPM is unavailable. Inspect the long-lived application container rather than the temporary validation container:

```bash
docker compose ps -a
docker compose logs --tail=100 app nginx
```

The most common first-start cause is one of the production safety checks rejecting `APP_KEY`, `DB_PASSWORD`, or `MOCK_DASHBOARD_PASSWORD` because it is empty or still contains the value from `.env.example`. Update `.env`, then run `make up` again.

If `/up` and the dashboard work but a particular mock returns nginx HTML `502`, inspect nginx's error log before changing matching rules. `upstream sent invalid header: "\x3a..."` indicates a replayed HTTP/2 pseudo-header such as `:status`. Current import skips these fields and the serving policy filters them from older response maps too. Rebuild and recreate `app`, `callback-worker` and `nginx` after applying the fix. No re-import is needed for serving protection; use **Update matching** only if you want stored response maps cleaned and missing example status codes inferred from `:status`.

A JSON `template_render_failed` error is separate: create the referenced variable in the active Environment or change the response template. A collection item without saved examples still needs a response configured before it can answer.

If PostgreSQL was already initialized before `DB_PASSWORD` changed, its persisted user password may no longer match `.env`. Preserve deployments should restore the password that initialized the volume or update the PostgreSQL role deliberately. Only for a disposable first-time database, remove the volume with `docker compose down -v` before restarting; that command permanently deletes stored endpoints and responses.

Open `http://localhost:18473/dashboard`. Verify:

- an unauthenticated request redirects to `/dashboard/login`;
- invalid credentials show a generic error;
- valid credentials open the endpoint registry;
- sign-out returns to the login screen;
- endpoint search, method, and enabled-state filters work;
- an endpoint can be disabled and re-enabled without deletion;
- **Copy mock curl** targets `APP_URL` and copies successfully;
- **Import / export** downloads a redacted JSON file and previews it without writing;
- request-log method, match, status, and row-limit filters work on desktop and narrow layouts.

Import/export smoke check:

1. Export one endpoint with secret redaction enabled.
2. Confirm a fresh export has `format: "mockdeck"`, exact string `format_version: "1.5"`, `$schema: "https://mockdeck.dev/schemas/config-v1.5.json"`, and no numeric database IDs or derived hashes. Legacy import versions accepted are integer `1` and strings `"1.0"`, `"1.1"`, `"1.2"`, `"1.3"`, `"1.4"` and `"1.5"`.
3. If the source curl contained auth, cookies, an API-key header, or a configured sensitive query key, confirm the value is absent and the endpoint is disabled with `requires_secret_replacement: true`.
4. Preview the file in `clone` mode; confirm no rows are written before **Apply import**.
5. Review and acknowledge warnings, apply, and confirm endpoint/response counts.

CLI equivalent:

```bash
docker compose exec app php artisan mockdeck:export --output=storage/app/mockdeck-smoke.json
docker compose exec app php artisan mockdeck:import storage/app/mockdeck-smoke.json --dry-run --mode=upsert
```

Response-template smoke check:

1. Add a response, choose **Body → Template**, and select JSON view.
2. Insert `{"id":"$string.uuid","count":"$number.int({\"min\":2,\"max\":2})","rows":{"$repeat":2,"$item":{"index":"$index","ip":"$internet.ip"}}}`.
3. Choose Fixed seed `42`, preview twice, and confirm output is identical, `count` is the number `2`, and the two row indexes are numeric `0` and `1`.
4. Change to Random and use **Regenerate**; Faker values may change while the JSON shape remains valid.
5. Enter `$internet.userName`; confirm it renders with a `RENAMED_METHOD` warning and quick fix to `internet.username`.
6. Enter a repeat count of `1001`; confirm Save is blocked with `LIMIT_EXCEEDED`.
7. Save a valid template, invoke the copied mock cURL, and confirm configured status/headers/delay plus JSON output and `X-Request-ID`.
8. Confirm the request-log row is marked templated and includes render time without the rendered body.

Callback smoke check (use a local disposable receiver):

1. Confirm `docker compose ps callback-worker` shows a running worker and `make health` remains healthy.
2. Configure a response callback with a JSON body, `{{env.KEY}}` and `$request.method`, a 2-second delay, two attempts, and a short timeout. Save the response.
3. Invoke the mock and verify its primary response returns before the callback delay expires; verify the callback appears later in **Callback log**.
4. Enable signing and enter a new secret. Independently compute lowercase hex HMAC-SHA256 over the exact callback body bytes; verify the configured signature header matches. Turn signing off and confirm the header is absent.
5. Choose **Send test callback** without prior traffic, then inspect the status and duration. Choose **Resend** and confirm a new attempt row links back to the original; the original is unchanged.
6. Verify a non-2xx response is retried only up to Max attempts, a stalled receiver times out, and neither affects the mock response or subsequent requests.
7. Export even with `--include-sensitive`; check that the signing secret, secret environment values, resolved callback payloads, and attempts are absent. Import a signed callback and confirm signing remains disabled until a new secret is entered.
8. Check the editor and callback log at 1440px, 1024px, and 390px in both themes; run axe on each view and resolve any accessibility findings before release.

## First-run clarity checks

`tests/Browser/first-run-smoke.mjs` checks created and imported zero-response endpoints, reactive editor cues, unchanged diagnostic 500/unmatched 404 behavior, the cookie help tip and conditional Logs hint at 390/740/1440px. Use an HTTP-only disposable Compose project with its own volumes and a fresh database for **each** theme. Assert `current_database()` equals that disposable database's name before any reset; never use an operator database. Point the fixture PHP process at the same instance/database, following the fixture conventions below.

```bash
MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BROWSER_THEME=light MOCKDECK_BASE_URL=http://127.0.0.1:YOUR_PORT node tests/Browser/first-run-smoke.mjs
```

Repeat with `MOCKDECK_BROWSER_THEME=dark` on another fresh disposable database. `MOCKDECK_PHP` selects the fixture's container PHP wrapper; `MOCKDECK_SCREENSHOT_DIR` optionally retains screenshots. Backend coverage is in `FirstRunClarityTest`.

## Wave 1 browser and runtime checks

With Playwright installed and a running app, run the existing layout smoke in both themes:

```bash
MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/layout-smoke.mjs
```

The selection/context workflow smoke creates named fixtures and a public `NAME` variable. Configure both the running app and the shell's PHP process with the **same dedicated disposable database**, app key, and environment; never point this fixture at an operator database. Migrate that database, then run:

```bash
MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/wave1-smoke.mjs
```

Set `MOCKDECK_PHP` if the fixture should use a specific PHP executable, and `MOCKDECK_SCREENSHOT_DIR` for a different screenshot directory. The workflow checks sequence drag/keyboard order, real calls and confirmed reset, rule conditions and exactly-one-fallback validation, protected fallback deletion, shared synthetic template preview, and JSON-only context editing. It checks 1440, 1024, and 390px in both themes.

Backend Wave 1 coverage is in `TemplateContextTest`, `RequestContextTemplateTest`, `ResponseSelectionTest`, `ConcurrentSequenceSelectionTest`, `WaveOneEditorTest`, `WaveOnePortabilityTest`, and `WaveOneInvocationTest`. The concurrency test starts 16 simultaneous independent processes twice (selection service and HTTP kernel), using disposable on-disk SQLite, and requires PDO SQLite and process spawning. It verifies positions 0–15 exactly once and counters of 16. Production database locking still requires validation on the deployed database; an unavailable Docker daemon is not a passing `make validate` result.

For manual acceptance, invoke templates containing method, ID, JSON path, and environment tokens; check missing paths return null and an oversized body (>65,536 bytes) returns null for body/JSON while Logs shows the structured warning. Verify all three sequence exhaust modes, per-environment reset, priority/AND rule matching and fallback, old revision defaults, format 1.3 round trips, old 1–1.2 Weighted imports, and absence of runtime state in export/import.

## Runtime smoke test

Create an enabled endpoint using this request and configure a `200` response body of `{"ok":true}`:

```bash
curl 'https://api.example.test/health'
```

Choose **Copy mock curl** or invoke the same path directly through MockDeck:

```bash
curl --fail-with-body \
  -H 'X-Request-ID: smoke-health-001' \
  http://localhost:18473/health
```

Expected results:

- status `200`;
- body `{"ok":true}`;
- response header `X-Request-ID: smoke-health-001`;
- a matched event appears in the dashboard request log.

Verify unmatched diagnostics:

```bash
curl --include \
  http://localhost:18473/not-configured
```

Expected result: JSON `404` with the received method and absolute mock URL.

Verify origin independence by creating the endpoint with the HTTPS upstream URL above and invoking it on the local HTTP origin. The request must still match because `/health` is identical.

## Database and matching checks

After updating an existing deployment:

```bash
docker compose exec app php artisan migrate:status
```

Confirm the relevant migrations are marked as run:

- `2026_09_16_000003_rebuild_origin_independent_signatures`
- `2026_09_18_000003_add_runtime_controls_to_mock_endpoints_table`
- `2026_09_19_000001_add_portable_uuids`
- `2026_09_24_000001_add_templating_to_mock_responses_table`
- `2026_09_25_000001_add_collections_tags_and_environments`
- `2026_09_25_000002_create_revisions_table`
- `2026_10_02_000001_add_response_selection`

Existing endpoints should report signature version `2` after migration.

Manual conflict scenario:

1. Create a header-agnostic endpoint for `GET https://api.example.test/customers` at priority `0`.
2. Create a header-specific endpoint for the same URL with `X-Tenant: blue` at priority `0`.
3. Invoke with `X-Tenant: blue`; the specific endpoint must win.
4. Raise the generic endpoint priority to `20`; invoke again; the explicit-priority endpoint must win.
5. Disable that endpoint; the specific enabled endpoint must win again.

An exact duplicate signature should be rejected by the endpoint form before saving.
Repeat that check with a different scheme/host but the same path; it must also be rejected as a duplicate.

### Collections, tags, and environments

1. Confirm migration creates exactly one default `Development` environment and existing endpoints still match without override rows.
2. Switch between Development and Staging and verify an explicit disabled override affects only Staging.
3. Create a secret variable through the API, then verify GET, duplicate, and export responses never contain its plaintext value.
4. Delete a collection and verify its endpoints remain with `collection_id = null`.
5. Duplicate an environment, edit the copy, and verify the source variables are unchanged.
6. Export one environment and verify inherited/enabled endpoints are present, explicit disabled overrides are absent, and secret values are null.

### Version history and import undo

1. Edit one endpoint three times and confirm three newest-first history rows with accurate summaries.
2. Save without changing a persisted value and confirm no revision is added.
3. Select two versions, then compare one with current; confirm both paths use the same structural diff viewer.
4. Restore the oldest version and confirm live values change while a new `rollback` revision is appended.
5. Preview and apply an Update-by-UUID import that changes an endpoint and response. Confirm both pre-states share one batch ID, then use **Undo this import** and verify both return to their pre-import values.

## Test coverage map

| Area | Representative coverage |
|---|---|
| Curl parsing | methods, quoted tokens, bodies, ignored flags, unsupported/file-backed input |
| Canonicalization | origin independence, query ordering, transport headers, JSON sorting, invalid JSON, URL safety |
| Signature variants | V1 through V5 and selected policy |
| Matching | hash, fallback, specificity, priority, disabled endpoints, empty response handling |
| Organization | collection deletion safety, case-insensitive tags, active-environment matching, override inheritance, environment duplication, write-only secrets, scoped export |
| Version history | three-edit sequencing, no-op suppression, structural/current diff parity, append-only restore, grouped import snapshots and atomic undo |
| Dashboard | CRUD, cross-origin duplicate prevention, copy-curl generation, filters, state toggling, authentication/logout |
| Portable config | UUIDs, deterministic/redacted export, validation, preview token/digest, exact/overlap conflicts, atomic create/upsert, HTTP and CLI flows |
| Responses | create, update, delete, unchanged weighted selection, atomic environment-scoped sequences, exhaustion/reset, ordered AND rules and fallback validation |
| Templates | typed/interpolated calls, directives, escapes, aliases, blocked methods, every limit, deterministic seeds, locales, Builder round trips, APIs, serving and error path |
| Logging | non-fatal handler failure, request IDs, rotated-file tailing, malformed-line tolerance, selection reason and oversized-context warning visibility |

## CI

`.github/workflows/ci.yml` repeats Composer validation, Pint, and the full PHPUnit suite on pushes and pull requests. A local `make validate` should pass before every push.

The tracked `.gitignore` placeholders under `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, and `storage/logs` are required. Git does not retain empty directories; removing these files makes a clean Composer install fail during Laravel `package:discover` with `Please provide a valid cache path.`

### Response save regression

Against the same dedicated fixture database/server, run `MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/response-save-smoke.mjs`. It checks sticky Save changes, persisted edits after reload, no accidental additional response, inline failure with draft retention, and corrected retry in both themes. `EndpointSaveDraftsTest` also covers transactional response/selection validation and empty header objects.


## Wave 2 acceptance

Run the full PHP suite, design-token validator, frontend tests, and both-theme layout smoke. `tests/Browser/wave2-smoke.mjs` exercises exclusion toggles, path chips, synthetic previews, fault controls and responsive action bounds with an isolated fixture database. Never use browser fixture scripts against an operator database.

PHP coverage includes migration safety for unchanged canonical bytes/hashes/versions, field exclusions and wildcard segment boundaries, literal priority, shared path context, format 1.4/legacy defaults, revision/runtime-state separation, token/session auth separation, real counters/predicates, bounded-history uncertainty and resets, and measurable/broken-body faults. Timeout is checked as a bounded plan; it is not claimed to provide a real TCP reset or indefinite socket hang.

## Make and GitHub CI

`make` is the supported convenience interface; its container targets call Docker Compose. Direct Compose commands remain useful for one-off diagnostics and work on machines without Make. Both honor `.env`'s `COMPOSE_FILE`, so `make up` starts the same-port HTTPS setup when that override is enabled. Failed startup now includes Nginx logs as well as app/database logs.

```bash
make up
make health                         # uses APP_URL from .env
make health APP_URL=https://localhost:18473
make https-check                    # checks both schemes on the same port
# With a CA not installed in the client's trust store:
MOCKDECK_SMOKE_CA=/path/to/rootCA.pem make https-check
# With Playwright installed and the dashboard accessible:
MOCKDECK_BASE_URL=http://localhost:18473 make browser-test
```

For a temporary origin override, pass `APP_URL` to `make health` and `make https-check`. Health and protocol smoke targets read `APP_URL` from `.env` unless explicitly overridden. `browser-test` runs the layout and documentation scripts in both themes. These browser and live-connectivity checks are separate from `make validate`, since they require a running dashboard and HTTPS certificates respectively.

`.github/workflows/ci.yml` runs two jobs: host PHP/Node tests plus browser checks against disposable SQLite, and the complete `make validate` container workflow followed by plain HTTP and dual HTTP/HTTPS smoke checks. CI generates a temporary localhost certificate and explicitly trusts it for the smoke client; it never uses the developer's certificates or disables TLS verification. Browser screenshots and the server log are retained for seven days. Container diagnostics run even after failure, and disposable volumes are removed at the end.

### CI starts in HTTP mode before enabling HTTPS

The container job explicitly sets `COMPOSE_FILE=docker-compose.yml`, `APP_PORT=18473`, and the HTTP `APP_URL` for its initial startup, and checks that the rendered Nginx configuration has no HTTPS mounts. This prevents a locally modified `.env.example` from enabling HTTPS in CI before its temporary certificates exist. The later HTTPS step overrides `COMPOSE_FILE` only after generating the certificate pair. If logs report `cannot load certificate /etc/nginx/certs/localhost.pem` during the plain-HTTP step, inspect the selected Compose files and Nginx mounts; increasing curl retries cannot fix missing certificates.

### Action runtimes and runner versions

CI pins both jobs to `ubuntu-24.04` so the moving `ubuntu-latest` label cannot silently change the operating system. Checkout, Node setup and artifact upload use their Node-24-based `v6` actions. `node-version: '22'` still selects Node.js 22 for application/frontend commands; an action's internal Node runtime is a separate setting. Treat future runner/action migrations as explicit updates and run the container/browser checks before adopting them.


## MCP Phase 1 regression checks

The full PHPUnit suite automatically includes `MutationCharacterizationTest`, `SharedMutationServiceTest`, `McpCredentialTest`, `McpCredentialCommandTest` and `McpAuditContractTest`. Both CI jobs already run this suite (`php artisan test` on the host and `make validate` in the container job). Tests use temporary databases and create no production MCP routes. The same checks cover unchanged Wave 2 verification authentication, dashboard draft/revision behavior, atomic service transactions, hash-only/revoked credentials, fixed permission/error contracts and redacted audit metadata.

Apply both `2026_10_18_*` migrations with normal startup before using credential CLI commands. Follow [docs/MCP.md](MCP.md) for commands and permissions. Phase 1 requires no MCP transport/client smoke test because it provides no transport or tool handlers. A Docker-less environment cannot certify the image/build portion of `make validate`; report that limitation separately from native PHP/frontend results.

## App recreation and stale Nginx upstream addresses

Nginx resolves the Compose `app` hostname through Docker DNS (`127.0.0.11`) using a variable FastCGI destination, with a five-second cache. Rebuilding/recreating the app can change its IP; an old literal `fastcgi_pass app:9000` configuration resolved only when Nginx started and could keep connecting to the previous address. The shared snippet covers plain HTTP and the same-port HTTP/HTTPS setup.

Nginx's Compose healthcheck requests `/up` through Nginx, PHP-FPM and Laravel, instead of treating a running Nginx process as proof of application reachability. A restart/recreation can still cause a brief unavailable window; this is recovery, not a zero-downtime guarantee.

For an existing installation that still has the old configuration loaded:

```bash
make nginx-reload
make health
```

The container CI job runs `scripts/smoke_upstream_recreation.py` on its disposable instance. It warms the original upstream, starts a second healthy app replica with automatic address allocation, removes the original, and requires `/up` recovery without recreating or restarting Nginx. Concurrently existing app containers guarantee distinct addresses without static IPs or custom subnet/IPAM configuration; the replacement remains the sole app afterward. This deliberately disrupts app networking and refuses to run unless `CI=true`; never run it on an operator instance.

## MCP Phase 2 isolation

The full PHPUnit suite includes `TestRunOwnershipTest`, `TestRunRoutingTest`, `TestRunCompatibilityTest`, and `ConcurrentRunRoutingTest`. The concurrency test uses real TCP requests, eight PHP server workers and 24 simultaneous clients: two isolated runs plus ordinary traffic, each with identical request shapes. Both routing mechanisms share the same load. CI repeats it against PostgreSQL in its own disposable `mockdeck_run_isolation_test` database; this is additional to the default SQLite run. See `docs/MCP.md` for the guarded PostgreSQL mode and reserved-path migration preflight. No MCP server or external agent service is needed.

Run-context tests use explicit incoming HTTP and HTTPS origins (including port 18473) rather than assuming the developer's `APP_URL` is `http://localhost`. Both response and callback assertions require the original request origin/query to survive while the run-routing prefix is removed. Changing local `APP_URL` must not change these test expectations or force application request URLs back to HTTP.

The setup test suite mocks the smoke-test orchestration to check the CI-only guard, automatic allocation, health-before-removal order, DNS recovery retries and unchanged Nginx identity/start time. The actual Docker DNS/network regression still runs in the container CI job.


The upstream smoke test requires the **Nginx configuration fix as well as the Python script**. Before removing any app container it checks `nginx -T` for Docker DNS resolution through the variable FastCGI destination and the two-second connect timeout; stale `fastcgi_pass app:9000` configurations fail immediately with application instructions. The connect timeout bounds attempts to dead IPs; `fastcgi_read_timeout 60s` remains unchanged for valid application responses. A failed recovery prints recent app/Nginx logs. After copying the shared snippet into an existing instance, run `make nginx-reload` before `make health`; changing the file on disk alone does not reload the running Nginx configuration.

## MCP Phase 3 protocol and tools

The full suite now includes `McpTransportTest`, `McpReadToolsTest`, `McpWorkflowTest`, `McpHistoryTest`, `McpSecurityBoundaryTest`, `McpIdempotencyTest`, `ConcurrentMcpMutationTest` and `McpClientIntegrationTest`. They run in both existing CI suite jobs. The real official PHP MCP client exercises HTTP and HTTPS with a locally generated certificate and explicit CA verification, not a fake HTTP facade. It also executes `scripts/mcp_example.php`. Concurrent clients prove one cached create and one winning update at a shared version; Phase 2’s concurrent run/ordinary traffic test remains mandatory.

`make mcp-test` selects the MCP checks. `make mcp-offline-test` starts the client integration test in a container with `--network none` (loopback only), disposable SQLite and no external services. The container CI job runs this explicitly. Missing Docker/network namespace capability must be reported as blocked, not inferred from ordinary loopback tests. The test-only TLS terminator proves client trust and protocol connectivity; production same-port Nginx TLS/DNS recovery remains covered by the existing container checks.

[docs/MCP.md](MCP.md) documents schemas, tested client/auth, the complete example workflow, evidence limits and troubleshooting. `mockdeck:mcp-schemas --check` is covered by `McpSchemaReferenceTest`. Never point disposable concurrency tests at a working database.

Container CI additionally runs `ConcurrentMcpMutationTest` with `MOCKDECK_MCP_CONCURRENCY_POSTGRES=true`, using the same dedicated `mockdeck_run_isolation_test` database after the routing test. It refuses other PostgreSQL database names. This exercises actual row locks rather than relying only on SQLite’s writer reservation.

### Dashboard UI regression checks

`make browser-test` remains a read-only layout/documentation smoke check. For a **disposable test database only**, run `MOCKDECK_BROWSER_FIXTURE=1 make browser-regression-test` with the same database environment as the running test server and `MOCKDECK_BASE_URL` set. This writes isolated fixture endpoints, Environments, responses, revisions and callback attempts. Never enable it against an operator database.

The CI browser job runs this suite after its normal smoke checks. It covers light/dark themes at 390/740/1440px, reachable Environment actions and error focus, retained drafts, History readability/focus return, nested Copy keyboard behavior, response identity/draft status, autocomplete through a Livewire morph, tooltip Escape, callback historical evidence/redaction, documentation search/jump, and Native/Postman prospective changes. Browser captures go to the existing CI artifact directory. Physical-device gestures and screen-reader speech still require manual testing.

The critique and endpoint-editor suites' zoom check is 200% **CSS zoom**, not real browser zoom. These maintained regression suites do not yet cover 320px, real browser zoom at 400%, axe scans or screen readers. Phase 0 findings are **known issues** documented separately in [PHASE0-RUNTIME.md](PHASE0-RUNTIME.md); they are not additional coverage in these scripts.

## Endpoint Create/Edit regression coverage

`make browser-regression-test` includes `tests/Browser/endpoint-editor-regressions.mjs` in the existing CI browser job. With the same explicitly disposable database and `MOCKDECK_BROWSER_FIXTURE=1`, it covers both themes at 390/740/1440px, local response save versus selection drafts, callback save/test provenance, N-shortcut cancellation, duplicate Builder recovery, tooltip descriptions, nested control reachability and 200% CSS zoom. It never sends a callback. It writes fixtures and must not target an operator database.

PHP regression classes `EndpointSelectionDraftTest`, `CallbackTestProvenanceTest`, `BuilderDuplicateKeysTest`, `EditorErrorDestinationsTest` and `EditorSequenceStatusTest` are discovered by the existing `php artisan test` job. Node tests exercise scoped dirty-state and error-focus behavior and catalog retry/cache recovery. CI configuration is not evidence of a successful remote run; record actual results separately.

## Workbench foundation verification

`make browser-test` now includes `tests/Browser/workbench-smoke.mjs`, also reached by the existing CI browser job. It reads the application without fixture writes and checks both themes, destination routes, persistent header, sidebar preference/overlay/Escape, the Editor / Callback / Settings task tabs and Request / Matching subtabs, tooltip hover and Escape, 24px help targets, single-character-shortcut opt-out, and document reflow at 1024/390/320px. Screenshots use MOCKDECK_SCREENSHOT_DIR.

`Tests\Feature\WorkbenchShellTest` checks route markup, bounded explorer pagination/search/active identity and ordinary/run/credential boundaries through the real Livewire component. `tests/Frontend/workbench.test.mjs` exercises storage and input preference state independently of a browser. The existing native format, matching, save-result, sensitive-output, callback and import suites remain release requirements. See [UI_UPGRADE.md](UI_UPGRADE.md) for completed and blocked migration items. A native PHP/SQLite check does not replace Docker/PostgreSQL or real zoom/screen-reader verification.


## Registry workspace milestone

Run `MOCKDECK_BROWSER_FIXTURE=1 make browser-registry-test` only against a **fresh disposable database** and its running instance (`MOCKDECK_BASE_URL`). The fixture refuses unrelated endpoint records, creates only Registry workspace examples and cleans its own endpoints between themes. CI runs this target before the other fixture-writing browser checks on its isolated SQLite instance. The workflow wiring does not itself prove a remote CI pass.

The script checks selected-state containment/control geometry at 1440/1024/740/390/320px in both themes, plus selected-option text fit at <=440px. At desktop it exercises method/state/collection/tag filters, result counts, name/priority sorting, pagination, native mixed selection, selection across pages/filters, every bulk action, independent persisted-state reads, row toggle/duplicate/delete/copy/edit, native 1.5 download and shared confirmation cancellation. It also checks empty states and keyboard collapse/open of recent activity. `RegistryWorkspaceTest` covers the same service-backed contracts in PHPUnit. Existing `endpoint-registry-smoke.mjs` remains the separate ten-width alignment/content-extreme regression; run it on a different fresh fixture database.

Browser output may be redirected outside the repository. Do not commit screenshots, runtime databases or generated logs. Full Docker/PostgreSQL validation, screen-reader announcements, touch and real 400% zoom require separate checks; native SQLite passes do not replace them.


## Endpoint workspace verification

`make browser-editor-test` runs `tests/Browser/endpoint-workspace-smoke.mjs` against an explicitly disposable instance (`MOCKDECK_BROWSER_FIXTURE=1`). The PHP fixture process and running application must use that same disposable database, key and configuration; never enable this fixture flag on an operator instance. It exercises both themes: new request → existing no-response diagnostic 500 → saved literal response 201, legacy deep links, one mounted response manager, saved-response/new-response draft retention, callback selection, inline collection/tag creation, invalid and successful global saves with independent database reads, task/subtab keyboard navigation, cancelled dirty navigation, clipboard copy and 1440/1024/390/320 geometry. Desktop workflows and viewport geometry are different assertions; this is not a claim that every advanced response operation was exercised at every width.

`EndpointWorkspaceTest` verifies task/deep-link mapping, signature/native-export immutability during navigation, sibling form/save boundaries, draft restoration and action-time Environment resolution through the shared `CopiesMockCurl` concern. Existing save-result, matching, selection, template, fault, callback and portability tests remain required. The browser helper `editor-navigation.mjs` operates the visible task/subtab controls and waits for the destination panel; it does not bypass them with legacy URLs. CI invokes the editor smoke after its fresh-database registry smoke. Remote CI execution must still be checked separately.

See the dated endpoint ledger in [UI_UPGRADE.md](UI_UPGRADE.md) for actual local results and BLOCKED Docker/PostgreSQL/accessibility checks.
