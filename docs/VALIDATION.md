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
2. a cached rebuild of the app image so validation always uses the current files
3. `composer validate --strict` in the app image
4. `vendor/bin/pint --test` in the app image
5. `php artisan test` against in-memory SQLite in the app image

The validation containers disable entrypoint migrations because an in-memory SQLite database exists only for one PHP process. Database-dependent tests use Laravel's `RefreshDatabase` trait so their schema is created inside the PHPUnit process that executes the assertions.

Run individual checks while iterating:

```bash
make compose-config
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
- request-log method, match, status, and row-limit filters work on desktop and narrow layouts.

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

Confirm both migrations are marked as run:

- `2026_09_16_000003_rebuild_origin_independent_signatures`
- `2026_09_18_000003_add_runtime_controls_to_mock_endpoints_table`

Existing endpoints should report signature version `2` after migration.

Manual conflict scenario:

1. Create a header-agnostic endpoint for `GET https://api.example.test/customers` at priority `0`.
2. Create a header-specific endpoint for the same URL with `X-Tenant: blue` at priority `0`.
3. Invoke with `X-Tenant: blue`; the specific endpoint must win.
4. Raise the generic endpoint priority to `20`; invoke again; the explicit-priority endpoint must win.
5. Disable that endpoint; the specific enabled endpoint must win again.

An exact duplicate signature should be rejected by the endpoint form before saving.
Repeat that check with a different scheme/host but the same path; it must also be rejected as a duplicate.

## Test coverage map

| Area | Representative coverage |
|---|---|
| Curl parsing | methods, quoted tokens, bodies, ignored flags, unsupported/file-backed input |
| Canonicalization | origin independence, query ordering, transport headers, JSON sorting, invalid JSON, URL safety |
| Signature variants | V1 through V5 and selected policy |
| Matching | hash, fallback, specificity, priority, disabled endpoints, empty response handling |
| Dashboard | CRUD, cross-origin duplicate prevention, copy-curl generation, filters, state toggling, authentication/logout |
| Responses | create, update, delete, weighted selection |
| Logging | non-fatal handler failure, request IDs, rotated-file tailing, malformed-line tolerance |

## CI

`.github/workflows/ci.yml` repeats Composer validation, Pint, and the full PHPUnit suite on pushes and pull requests. A local `make validate` should pass before every push.

## Environment note for this delivery

The delivery workspace used to prepare this ZIP did not provide PHP, Composer, Docker, or Podman. The following non-runtime checks were executed there:

- `git diff --check`
- POSIX shell syntax validation of `docker/entrypoint.sh`
- repository and archive integrity checks

Run `make validate` in a Docker-capable environment to execute the PHP formatter and test suite before deployment.
