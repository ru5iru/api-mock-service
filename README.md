# MockDeck

MockDeck is a self-hosted Laravel service that converts real `curl` commands into reusable mock endpoints. It canonicalizes incoming requests, resolves matching endpoints deterministically, returns configured weighted responses, and emits structured JSON request logs without storing request events in the database.

The stack is Laravel 13, Livewire 4, PostgreSQL 16, PHP-FPM, nginx, and Docker Compose.

## Highlights

- Safe text-only curl parsing; pasted commands are never executed
- Origin-independent method, path/query, header, and body canonicalization
- Five endpoint-local cookie/auth/header signature policies
- Deterministic conflict resolution by priority, specificity, then endpoint ID
- Endpoint enable/disable controls and duplicate-signature prevention
- Collections, case-insensitive tags, and global runtime environments with write-only secret variables
- Multiple responses with weighted random selection and bounded delay
- Opt-in JSON response templates with a schema builder, FakerPHP-backed Faker.js method mapping, validation, preview, and deterministic seeds
- Password-protected dashboard with rate-limited login
- Responsive endpoint and request-log filtering
- One-click mock-host curl generation with clipboard fallback
- Versioned JSON import/export with default secret redaction and atomic preview/apply
- Flat JSON request logs, request IDs, rotation, and bounded cross-file tailing
- Production startup guards for placeholder secrets
- PHPUnit regression suite, Pint checks, Compose validation, and CI workflow

## Feature guide

| Feature | What it provides | Where to use it |
|---|---|---|
| cURL endpoint creation | Safe parsing, canonical preview, matching policy, duplicate detection | **Endpoints → New endpoint** |
| Endpoint registry | Search/filter/sort, enable/disable, duplicate, copy mock cURL, row and bulk actions | **Endpoints** |
| Collections and tags | Organize, filter, bulk-move, and bulk-tag endpoints without changing signatures | **Endpoints** and endpoint editor |
| Environments | Global active environment, per-endpoint availability overrides, variables, duplication, and scoped export | Header switcher and **Environments** |
| Exact request matching | Origin-independent V1–V5 signatures with deterministic priority/specificity resolution | Endpoint editor and runtime |
| Response pools | Multiple status/header/body/delay responses selected by relative weight | Endpoint response editor |
| Response templating | Builder and JSON views, mapped Faker catalog, autocomplete, validation, preview, seeds, locales | Response **Body → Template** |
| Request observability | Structured request IDs/logs, filters, repeat grouping, unmatched diagnostics, row details | **Request log** |
| Configuration transfer | Redacted deterministic export, preview-first create/upsert/clone import, CLI commands | **Import / export** |
| Dashboard security | Rate-limited login, persistent Livewire access middleware, safe production defaults | `/dashboard/login` and `.env` |
| UI preferences | System/Light/Dark theme, compact/comfortable log density, keyboard shortcuts | Header controls and Request log |
| Validation and CI | Compose validation, design-token checks, frontend tests, Pint, PHPUnit | `make validate` and GitHub Actions |

The complete operator reference is [docs/USER_GUIDE.md](docs/USER_GUIDE.md). The same core workflows are available from **Documentation** in the dashboard.

## Secure first start

Requirements: Docker Engine, Docker Compose v2, `make`, and `openssl`.

```bash
cp .env.example .env
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
```

Copy the generated key into `.env`, then replace these placeholders:

```dotenv
APP_KEY=base64:generated-value
DB_PASSWORD=a-unique-local-database-password
MOCK_DASHBOARD_USERNAME=admin
MOCK_DASHBOARD_PASSWORD=a-unique-dashboard-password
```

Start the service:

```bash
make up
make health
```

Open <http://localhost:18473/dashboard> and use the configured dashboard credentials.

The production container refuses known placeholder application, database, and dashboard secrets. Only nginx is published, on `127.0.0.1:18473` by default; PHP-FPM and PostgreSQL stay on the internal Compose network.

To change the host port, keep `APP_PORT` and `APP_URL` aligned:

```dotenv
APP_PORT=19483
APP_URL=http://localhost:19483
```

For a deliberately unauthenticated local-only dashboard, set `MOCK_DASHBOARD_AUTH_ENABLED=false`. Do not use that setting on an exposed host.

## Configure and invoke a mock

1. Sign in and choose **New endpoint**.
2. Paste the real request as curl.
3. Choose enabled state, priority, collection, tags, and optional environment overrides.
4. Choose whether cookies, authentication, or all headers should be ignored.
5. Review the parsed request, canonical string, signature variant, and SHA-256 digest.
6. Save it and configure one or more responses.

The editor ignores authentication by default to reduce accidental credential persistence in signatures. Review pasted curl content before saving because the original command remains visible to authorized dashboard operators.

After saving, choose **Copy mock curl** beside the endpoint. MockDeck replaces the upstream origin with `APP_URL` while preserving the method, path, query, headers, and body:

```bash
curl 'http://localhost:18473/v1/items?limit=10' \
  -H 'X-Request-ID: example-001' \
  -H 'Content-Type: application/json' \
  --data '{"name":"Example"}'
```

No original-URL header is required. Signatures deliberately ignore scheme, host, and port, so an endpoint configured for `https://api.example.test/v1/items` can be invoked through `http://localhost:18473/v1/items`. Absolute HTTP/HTTPS input is still required when configuring an endpoint, and URL-embedded credentials remain rejected.

Every response includes `X-Request-ID`. A valid caller-provided ID is retained; otherwise MockDeck generates a UUID.

## Manage endpoints and responses

The endpoint registry searches name, method, path, and raw cURL; filters by method/state; and sorts by recent update, name, or priority. Each row can be opened, enabled/disabled, duplicated, exported, deleted, or copied as a MockDeck-host cURL. Selecting rows exposes bulk enable, disable, move-to-collection, add-tags, export, and delete actions.

Collections and tags organize the registry without affecting request signatures. Filter by collection or multiple tag chips, show tags on endpoint rows, and use the bulk bar to move endpoints or attach tags. Deleting a collection keeps every endpoint and clears only its collection assignment.

Each endpoint has a response pool. A response stores status, header JSON, bounded delay, positive weight, and either an exact static body or a JSON template. Selection probability is proportional to response weight.

## Use environments

MockDeck creates **Development** as the default active environment during migration. Existing endpoints have no overrides, so their matching behavior remains unchanged. Use the header environment switcher to change the global runtime environment, or open **Environments** to:

- create, rename, duplicate, delete, and choose the default environment;
- add public or secret key/value variables;
- keep secrets write-only after creation; and
- edit a duplicate independently of its source.

An endpoint matches only when its normal endpoint state is enabled and its active-environment override is either absent or enabled. In the endpoint editor, **Inherit endpoint state** creates no override. Request-log entries record the active environment; older entries show an em dash.

Environment-scoped exports include endpoints with no override for that environment and endpoints with an enabled override. An explicit disabled override excludes the endpoint. Environment secrets are always redacted, including when request-secret redaction is disabled.

## Use response templates

Choose **Body → Template** in a response editor:

1. Use **Builder** for representable object/list schemas or **JSON** for the complete language.
2. Choose Random, Fixed, or Request signature seed mode and a supported locale.
3. Insert mapped Faker.js-shaped methods such as `$person.fullName`, `$internet.ip`, or `$number.int({"min":1,"max":10})`.
4. Preview output and resolve validation errors. Warnings, including renamed legacy aliases, remain saveable.
5. Save and call the mock. Live serving uses the same compiler/renderer as preview.

Useful template forms:

```json
{
  "id": "$string.uuid",
  "message": "Hello {{person.firstName}}",
  "items": {
    "$repeat": 3,
    "$item": {"position": "$index", "price": "$commerce.price"}
  }
}
```

Templates are parsed without evaluation. Reserved prototype names and unsafe/unbounded helpers are blocked; size, depth, node, repeat, argument, and output limits are configurable. Static response bodies remain untouched even when they contain `$` or `{{`.

See [docs/USER_GUIDE.md#7-build-a-response-template](docs/USER_GUIDE.md#7-build-a-response-template) for Builder/JSON usage and [the language reference](docs/USER_GUIDE.md#8-template-language-reference) for directives, escapes, aliases, limits, seeds, and runtime errors.

## Import and export configuration

Open **Import / export** in the dashboard to download all endpoints or a selected subset. Native exports include endpoint and response UUIDs, request matching options, and response behavior. Numeric database IDs and derived hashes are never exported.

Secret redaction is enabled by default. It removes configured authentication, cookie, API-key headers, and sensitive query values. Any affected endpoint is exported disabled with `requires_secret_replacement: true`; after import, review its curl and add deployment-appropriate credentials before enabling it.

Imports are preview-first. Choose `create-only`, `upsert`, or `clone`, upload the JSON file, review errors and overlap warnings, then explicitly apply it. The apply step verifies the preview digest, repeats conflict checks under database locks, and commits all endpoint/response writes in one transaction.

Equivalent commands are available for repeatable workflows:

```bash
php artisan mockdeck:export --output=mockdeck.json
php artisan mockdeck:export --endpoint=<endpoint-uuid> --output=subset.json
php artisan mockdeck:import mockdeck.json --dry-run --json
php artisan mockdeck:import mockdeck.json --mode=upsert --acknowledge-warnings
```

`--include-sensitive` is an explicit opt-in for CLI exports. Treat those files as credentials. See [docs/CONFIG_IMPORT_EXPORT.md](docs/CONFIG_IMPORT_EXPORT.md) for the document contract, HTTP endpoints, limits, conflict rules, and recovery guidance.

## Matching model

The canonical request is:

```text
METHOD
URL_PATH_WITH_SORTED_QUERY
lowercase-header:value

CANONICAL_BODY
```

JSON object keys are sorted recursively, while array order is retained. Non-JSON bodies are trimmed but otherwise unchanged. Header names are lowercased and header rows are sorted. Unstable transport headers such as `Host`, `Content-Length`, `User-Agent`, `Accept`, `Connection`, and `X-Request-ID` are excluded automatically.

| Variant | Included in the signature | Specificity |
|---|---|---:|
| V1 | Method, path/query, meaningful headers, body | 50 |
| V2 | V1 without `Cookie` | 40 |
| V3 | V1 without configured auth headers | 40 |
| V4 | V1 without cookies or auth headers | 30 |
| V5 | Method, path/query, body only | 10 |

Invocation computes all five candidates and performs one indexed hash lookup. If hashes drift while canonical text remains compatible, a method-scoped canonical-string lookup provides a warning-level fallback.

When several enabled endpoints match, the winner is selected by:

1. highest explicit `priority`;
2. highest signature specificity from the table;
3. lowest endpoint ID as a final stable tie-breaker.

An exact duplicate signature is rejected by the endpoint form, including requests that differ only by upstream origin. Broader and narrower endpoints may coexist; priorities make an intentional override explicit.

Header-inclusive signatures remain exact for headers not classified as transport metadata. A client-added application header that is absent from the saved curl can prevent V1 through V4 from matching. Choose **Ignore all headers** when headers are not part of the behavior under test.

## Responses and failures

Each endpoint may have multiple responses. Selection probability is proportional to each positive integer `weight`.

A response provides:

- HTTP status from 100 through 599;
- response headers from a JSON object;
- exact body text without re-encoding;
- artificial delay bounded by `MOCK_MAX_DELAY_MS`.

The body can remain static or opt into JSON templating. Template mode includes a schema builder and raw JSON editor, a server-built Faker catalog, fixed/request-derived seeds, locale selection, inline validation, and server-rendered preview. Static response bodies containing `$` or `{{` remain exact text. The PHP backend maps the supported Faker.js-shaped method names to `fakerphp/faker`; it does not ship Faker in the browser bundle.

Runtime failure semantics:

| Condition | Result |
|---|---|
| URL cannot be normalized | diagnostic JSON `400` |
| No enabled endpoint matches | diagnostic JSON `404` |
| Endpoint has no response | diagnostic JSON `500` with endpoint ID |
| Endpoint and response match | configured status, headers, body, and delay |
| Saved template fails at runtime | JSON `500` with `X-MockDeck-Template-Error: 1` and the failing template path/token |
| Unexpected internal exception | structured error-class event, then Laravel exception handling |

## Dashboard access

Configuration:

```dotenv
MOCK_DASHBOARD_AUTH_ENABLED=true
MOCK_DASHBOARD_USERNAME=admin
MOCK_DASHBOARD_PASSWORD=a-unique-dashboard-password
```

Login attempts are rate-limited. Successful login regenerates the session ID; sign-out invalidates the session and CSRF token. The custom access middleware is persisted onto Livewire update requests so actions cannot outlive dashboard authorization.

For shared or internet-reachable deployments, also terminate TLS upstream, restrict network access, rotate credentials, and establish PostgreSQL/log-volume backups.

## Request logs

The `mock_requests` channel writes one flat JSON event to stdout and/or daily files under `storage/logs`. A logging-handler failure is swallowed and reported to the process error stream so it cannot change a configured mock response.

Example:

```json
{"timestamp":"2026-09-18T10:22:31.000Z","request_id":"example-001","method":"POST","url":"http://localhost:18473/v1/items","matched":true,"match_tier":"hash","matched_variant":"V3","endpoint_id":42,"response_id":7,"status_code":201,"delay_ms":0,"duration_ms":2.14,"level":"info"}
```

The dashboard reads a byte-bounded tail across current and rotated files, skips malformed/partial JSON lines, and displays newest events first.

```dotenv
MOCK_LOG_STDOUT=true
MOCK_LOG_FILE=true
MOCK_LOG_DAYS=14
```

## Validation

Run the full release check:

```bash
make validate
```

Useful focused commands:

```bash
make compose-config
make composer-validate
make lint
make test-unit
make test-feature
make test
make format
```

The test workflow uses an ephemeral app container with in-memory SQLite and dashboard authentication disabled only for the test environment.

Run `make format` before committing formatter changes. The format target bind-mounts the repository so Pint writes to the host working tree. Required storage-directory placeholders are tracked so a clean GitHub Actions checkout can complete Composer `package:discover`.

See [docs/VALIDATION.md](docs/VALIDATION.md) for environment setup, smoke tests, manual conflict checks, and the coverage map.

## Operations

```bash
make logs       # follow nginx and app output
make shell      # open a shell in the running app container
make down       # stop services and retain named volumes
make build      # rebuild without layer cache
```

The app container runs pending database migrations on startup when `RUN_MIGRATIONS=true`. The origin-independent migration rebuilds stored canonical strings/hashes and advances them to signature version 2.

The default image contains development dependencies so validation commands work. Set `BUILD_APP_ENV=production` before a production rebuild to omit them; run validation before producing that image.

## Project map

```text
app/
  Http/Controllers/                dashboard auth and invocation boundaries
  Livewire/Admin/                  dashboard endpoint, response, and log UI
  Services/Curl/                   parser, canonicalizer, signature variants, and mock-curl builder
  Services/Config/                 native document export, validation, preview, and atomic import
  Services/Matching/               deterministic endpoint resolution
  Services/Response/               weighted selection strategy
  Services/Logging/                non-fatal writer and bounded rotated-log reader
  Services/Templates/              template compiler, mapped Faker catalog, schema projection, and renderer
database/migrations/               endpoint and response schema
docs/                              UI, operator, architecture, transfer, and validation guides
routes/web.php                     authenticated dashboard routes
routes/mock.php                    root and catch-all invocation routes
tests/                             unit and feature regression coverage
```

Documentation map:

- [User guide](docs/USER_GUIDE.md) — every implemented feature and operator workflow
- [Configuration import/export](docs/CONFIG_IMPORT_EXPORT.md) — format, conflict, security, HTTP, and CLI contract
- [Validation guide](docs/VALIDATION.md) — automated checks and manual smoke scenarios
- [Architecture guide](docs/ARCHITECTURE.md) — matching, templates, persistence, and extension boundaries
- [UI guide](docs/UI_GUIDE.md) — tokens, components, themes, copy, code surfaces, and accessibility

Read the architecture guide before changing canonicalization, matching, response rendering, or portable configuration.

## Current limitations

- Matching is exact within one of five fixed header policies; selective query/header/body predicates are not yet available.
- Upstream origin is intentionally not a discriminator; two otherwise identical requests on different upstream hosts share a signature.
- Responses are weighted, not rule-selected or scenario-state driven.
- Response templates currently produce JSON; request-context tokens and non-JSON interpolation are not implemented.
- Builder is a lossless subset of the JSON template language and disables itself for unsupported structures.
- OpenAPI generation, recording/proxying, and verification assertions are planned, not implemented.
- Native JSON import/export is implemented; third-party OpenAPI, Postman, WireMock, Mockoon, and Hoverfly adapters remain planned.

## Framework references

- [Laravel 13 documentation](https://laravel.com/docs/13.x)
- [Livewire 4 documentation](https://livewire.laravel.com/docs/4.x)
- [Docker Compose documentation](https://docs.docker.com/compose/)
