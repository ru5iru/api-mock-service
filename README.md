# MockDeck

MockDeck is a self-hosted Laravel service that turns a real `curl` command into an exact request signature and returns one of the responses configured for it. Matching is hash-first, supports endpoint-specific cookie/auth/header exclusions, and falls back to canonical-string comparison if a digest does not match.

The application uses Laravel 13, Livewire 4, PostgreSQL 16, PHP-FPM, and nginx. Request events go to structured JSON logs rather than a database table.

## Start it

Requirements: Docker Engine with Docker Compose v2.

```bash
cp .env.example .env
docker compose up -d --build
```

Open <http://localhost:18473/dashboard>. MockDeck uses the uncommon host port `18473` by default and binds it only to `127.0.0.1`. To choose another host port, change both values in `.env` so generated URLs and Docker's published port stay aligned:

```dotenv
APP_PORT=19483
APP_URL=http://localhost:19483
```

Only nginx is published to the host. PHP-FPM's port `9000` and PostgreSQL's port `5432` remain internal to the Compose network, so they do not conflict with services running on the host.

The app container runs migrations on startup, including rebuilding older endpoint signatures when canonicalization changes. The Compose file ships with a predictable development `APP_KEY`; replace it in `.env` before deploying anywhere shared:

```dotenv
APP_KEY=base64:replace-with-32-random-bytes-in-base64
DB_PASSWORD=replace-this-too
```

Useful commands:

```bash
make logs       # follow nginx and application output
make test       # run PHPUnit in an ephemeral app container
make down       # stop services, retaining named volumes
```

## Configure and invoke a mock

1. Choose **New endpoint** in the dashboard.
2. Paste the real request as curl.
3. Choose whether cookies, auth headers, or all headers should be ignored.
4. Review the parsed request, canonical string, variant, and SHA-256 digest.
5. Save it and add at least one response.

Choose **Copy mock curl** beside an endpoint to copy an invocation command using `APP_URL`. No original-URL header is required: matching deliberately ignores the scheme, host, and port, so endpoints configured for `api.example.test` and `api2.example.test` with the same path, query, method, headers, and body share the same signature.

```bash
curl 'http://localhost:18473/v1/items?limit=10' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
```

Header-inclusive variants are exact for explicitly meaningful headers. Automatically supplied transport headers (`Host`, `Content-Length`, `User-Agent`, `Accept`, `Accept-Language`, `Accept-Charset`, `Accept-Encoding`, and `Connection`) are always ignored because clients and proxies generate them. Use **Ignore all headers** when every remaining request header should be excluded.

## Matching pipeline

Every request becomes a canonical string:

```text
METHOD
URL_PATH_WITH_SORTED_QUERY
lowercase-header:value

CANONICAL_BODY
```

JSON object keys are sorted recursively when the content type is JSON. Array order is retained. Non-JSON bodies are trimmed but otherwise unchanged. Header names are lowercased and header rows are sorted.

At invocation time, MockDeck computes these candidates:

| Variant | Included in the signature |
|---|---|
| V1 | Method, URL path/query, all headers, body |
| V2 | V1 without `Cookie` |
| V3 | V1 without configured auth headers |
| V4 | V1 without cookies or auth headers |
| V5 | Method, URL path/query, and body only |

The candidates are queried in one indexed `WHERE IN` lookup. The endpoint's own exclusion flags determine which one was stored. If that tier misses, the matcher performs a method-scoped lookup using the five canonical strings themselves. A fallback match is logged at warning level because it normally indicates hash-version drift or a digest defect.

If duplicate endpoint signatures exist, the oldest endpoint ID wins. This is deterministic, but duplicate signatures should generally be avoided.

## Responses

Each endpoint can own any number of responses. A single response is always returned. With multiple responses, selection probability is proportional to each positive integer `weight`. The selected response contributes:

- HTTP status from 100 through 599
- response headers from a JSON object
- body sent without re-encoding
- artificial delay, bounded by `MOCK_MAX_DELAY_MS` (30 seconds by default)

An endpoint with no response returns a diagnostic `500`. A request with no endpoint returns:

```json
{
  "error": "No mock configured for this request",
  "method": "GET",
  "url": "http://localhost:18473/not-configured"
}
```

## Logs

The dedicated `mock_requests` channel writes flat, one-line JSON to stdout and a daily rotating file under `storage/logs/mock-requests-YYYY-MM-DD.log`. The dashboard tails a bounded portion of the newest file; it does not create a request-log table.

Example event:

```json
{"timestamp":"2026-09-16T10:22:31.000Z","method":"POST","url":"/v1/users/123","matched":true,"match_tier":"hash","matched_variant":"V3","endpoint_id":42,"response_id":7,"status_code":200,"delay_ms":150,"duration_ms":152.14,"level":"info"}
```

Set `MOCK_LOG_STDOUT=false` or `MOCK_LOG_FILE=false` to disable either destination. The Compose `mock_logs` volume preserves file logs across container replacement.

## Tests

```bash
docker compose run --rm \
  -e APP_ENV=testing \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  app php artisan test
```

The suite covers parsing, query/header/JSON normalization, all five hash variants, approximate weighted distribution, hash and fallback matching, reordered query/body keys, ignored extra headers, unmatched diagnostics, and dashboard access.

## Project map

```text
app/
  Http/Controllers/MockInvocationController.php
  Livewire/Admin/                         dashboard components
  Services/Curl/                          parser, normalizer, variants
  Services/Matching/EndpointMatcher.php   indexed lookup + fallback
  Services/Response/                      selection interface + weighted strategy
  Services/Logging/                       request logger + bounded log tail
database/migrations/                      two domain tables only
routes/web.php                            protected-by-alias dashboard routes
routes/mock.php                           root and catch-all invocation routes
docker/                                   PHP and nginx runtime configuration
tests/                                    unit and feature coverage
```

Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) before modifying canonicalization or matching. Those two layers must evolve together.

## Security and deployment notes

`dashboard.access` is a no-op middleware today. Keep `/dashboard` on trusted infrastructure, behind a VPN, or behind nginx basic auth. Later, replace the alias in `bootstrap/app.php` with Laravel `auth`; dashboard routes and controllers do not need to change.

The pasted curl is parsed as text and never passed to a shell. File-backed request bodies and multipart curls are rejected because their bytes or generated boundaries cannot be reproduced safely from pasted text.

For production, terminate TLS upstream, provide non-default secrets, back up the PostgreSQL volume, and decide whether both stdout and rotating-file logging are needed. Redis is not required; a cache-backed matcher can be added behind `EndpointMatcher` if indexed database lookup ever becomes a measured bottleneck.

The default Compose build includes development dependencies so `make test` works. Set `BUILD_APP_ENV=production` before a production rebuild to omit them.

## Framework references

- [Laravel 13 documentation](https://laravel.com/docs/13.x)
- [Livewire 4 documentation](https://livewire.laravel.com/docs/4.x)
- [Docker Compose documentation](https://docs.docker.com/compose/)
