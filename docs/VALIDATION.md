# Validation guide

This guide is the release checklist for the fixed MockDeck service. Run it from the repository root.

## Prerequisites

- Docker Engine with Docker Compose v2
- `make`
- `curl` for the health and smoke checks
- `openssl` for generating local secrets

The Docker workflow is the canonical path. It pins the runtime through the project image and does not require host PHP or Composer.

## One-time environment setup

```bash
cp .env.example .env
```

Generate an application key and choose non-placeholder database/dashboard passwords:

```bash
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
```

Copy the printed value into `.env`, then set:

```dotenv
DB_PASSWORD=a-unique-local-database-password
MOCK_DASHBOARD_USERNAME=admin
MOCK_DASHBOARD_PASSWORD=a-unique-dashboard-password
```

The production container intentionally refuses to start with the placeholders from `.env.example`.

## Complete automated validation

```bash
make validate
```

`make validate` runs, in order:

1. `docker compose config --quiet`
2. design-token literal and light/dark contrast validation
3. frontend Node tests for theme, preferences, layouts, template editor, and icons
4. a cached rebuild of the app image so validation always uses the current files
5. `composer validate --strict` in the app image
6. `vendor/bin/pint --test` in the app image
7. `php artisan test` against in-memory SQLite in the app image

The validation containers disable entrypoint migrations because an in-memory SQLite database exists only for one PHP process. Database-dependent tests use Laravel's `RefreshDatabase` trait so their schema is created inside the PHPUnit process that executes the assertions.

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
docker compose logs --tail=100 app
```

The most common first-start cause is one of the production safety checks rejecting `APP_KEY`, `DB_PASSWORD`, or `MOCK_DASHBOARD_PASSWORD` because it is empty or still contains the value from `.env.example`. Update `.env`, then run `make up` again.

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
2. Confirm the JSON has `format: "mockdeck"`, a supported `format_version` (`1`, `"1.0"`, `"1.1"`, or `"1.2"`), and no numeric database IDs or derived hashes.
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
| Responses | create, update, delete, weighted selection |
| Templates | typed/interpolated calls, directives, escapes, aliases, blocked methods, every limit, deterministic seeds, locales, Builder round trips, APIs, serving and error path |
| Logging | non-fatal handler failure, request IDs, rotated-file tailing, malformed-line tolerance |

## CI

`.github/workflows/ci.yml` repeats Composer validation, Pint, and the full PHPUnit suite on pushes and pull requests. A local `make validate` should pass before every push.

The tracked `.gitignore` placeholders under `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, and `storage/logs` are required. Git does not retain empty directories; removing these files makes a clean Composer install fail during Laravel `package:discover` with `Please provide a valid cache path.`
