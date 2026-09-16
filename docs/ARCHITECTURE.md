# Architecture and extension guide

## Boundaries

MockDeck deliberately separates administration from invocation:

- `routes/web.php` contains only dashboard routes. They all use the named `dashboard.access` middleware alias.
- `routes/mock.php` contains the root and catch-all invocation routes. Dashboard, Livewire, health, and static-asset prefixes are excluded from the catch-all expression.
- Livewire components own dashboard state and CRUD behavior. They call the same curl services used by request invocation.
- `MockInvocationController` coordinates the runtime pipeline but does not implement parsing, hashing, database matching, selection policy, or log formatting.

This makes adding login, changing the dashboard, or replacing response selection independent of exact-request matching.

## Why incoming requests need five variants

An endpoint stores one digest based on the exclusions selected when it was saved. An incoming request does not carry that configuration, so the service cannot compute one authoritative digest before looking up an endpoint.

`CurlHasher::variants()` therefore produces a fixed candidate set:

1. V1 — full headers
2. V2 — no Cookie header
3. V3 — no configured auth headers
4. V4 — no Cookie or auth headers
5. V5 — no headers

`EndpointMatcher` sends all distinct hashes to one indexed query. Adding arbitrary, endpoint-defined header exclusion lists would break the fixed-set guarantee and requires a new indexing strategy. Do not add a sixth policy in the dashboard without adding the corresponding invocation candidate and tests.

`exclude_headers` takes precedence over the other two flags. The UI clears cookie/auth flags when it is enabled, and the saved record writes those flags as false. This keeps one endpoint mapped to one meaningful variant.

## Canonicalization contract

Both `CurlParser` output and `IncomingRequestFactory` output are `ParsedCurl` values. `CurlNormalizer` is the only canonicalization implementation.

The contract is:

- uppercase method;
- scheme, host, user info, and port ignored; only the path and query participate in matching;
- query pairs sorted by decoded key, while repeated values for a key retain their original order;
- percent encoding emitted consistently with `rawurlencode`;
- automatically generated transport headers dropped, then remaining header names lowercased, values trimmed, and rows sorted by name/value;
- configured exclusions applied after content type is detected;
- JSON objects sorted recursively, JSON arrays kept in order;
- invalid JSON and non-JSON bodies retained as trimmed text;
- canonical sections joined as `method + URL + headers + blank line + body`;
- SHA-256 emitted as a lowercase 64-character hex digest.

Changing any rule changes stored signatures. If a change is unavoidable, plan a signature version column or a data migration. The current canonical-string fallback only protects against digest changes when canonical text remains compatible; it is not a substitute for versioning the canonical format.

## Origin-independent matching

The stored curl can describe any real upstream origin. `CurlNormalizer` drops that origin and retains the path and normalized query, allowing the same invocation through the configured mock host without a control header. Consequently, two configured URLs whose only difference is origin have the same signature; the oldest endpoint ID wins if both are saved.

## Matching behavior

`EndpointMatcher` performs:

1. `curl_hash IN (...)`, ordered by endpoint ID;
2. if absent, `method = ? AND normalized_curl IN (...)`, also ordered by ID.

Responses are eager-loaded with the endpoint to avoid a second endpoint lookup. The fallback result carries tier `fallback`; `MockRequestLogger` raises that one request event to warning level. No additional warning line is written, preserving the one-event-per-request log contract.

Digest collisions are cryptographically improbable. Duplicate saved signatures are possible; lowest endpoint ID wins to make behavior stable.

## Response strategy

`ResponseSelectorInterface` accepts the endpoint response collection and returns one response. `WeightedRandomSelector` treats every weight as at least one defensively, although dashboard validation and the schema default keep valid records positive.

To add round-robin selection:

1. implement `ResponseSelectorInterface`;
2. store any required cursor outside request-log storage;
3. change the binding in `AppServiceProvider` or make it config-driven;
4. add concurrency tests before enabling it.

A rule-based selector that depends on incoming query/header values would require widening the interface to accept an immutable invocation context. Do that at the interface boundary rather than reading the global request inside a selector.

## Request logging

`MockRequestLogger` targets only the `mock_requests` channel. `FlatJsonFormatter` merges scalar context into a single JSON object and emits one newline. File and stdout handlers share the formatter.

The controller logs after delay and response construction so `duration_ms` includes artificial delay. Expected outcomes use info level. Fallback uses warning. An exception is logged with its class and rethrown to Laravel's exception handler.

`LogTailer` reads at most `MOCK_LOG_TAIL_MAX_BYTES` from the newest rotated file, parses valid JSON lines, and returns newest first. This bound is important; do not replace it with `file()` on a production log.

## Dashboard access

The `dashboard.access` alias points to `DashboardAccess`, which calls the next middleware without authentication. When authentication is introduced, change the alias target or add `auth` within the alias. Do not add auth conditions throughout Livewire components or controllers.

The dashboard can display raw curl, canonical text, request bodies, and headers. Those may contain secrets. Access control and log retention should be treated as deployment requirements before exposing the service beyond a trusted development network.

## Database

Only two domain tables exist:

- `mock_endpoints` stores the original curl, one canonical representation, one digest, and exclusion flags;
- `mock_responses` stores status, header JSON, body, delay, and weight.

Request logs never enter PostgreSQL. Deleting an endpoint cascades to its responses.

## Failure semantics

| Condition | Result |
|---|---|
| No endpoint | JSON `404` with method and received mock URL |
| Endpoint has no response | JSON `500` naming the endpoint ID |
| Endpoint and response found | Configured status, headers, exact body, and bounded delay |
| Internal exception | Structured error-class log event, then normal Laravel exception handling |

These diagnostics are intentional API behavior and should be preserved in client-facing changes.

## Operational extension points

- Redis can cache digest-to-endpoint IDs, but database invalidation must occur after every dashboard save/delete.
- Telescope can be installed for development inspection independently of mock request logs.
- Promtail/Loki/Grafana can consume stdout or the mounted rotating files without changing application logging.
- nginx basic auth can protect the dashboard immediately; Laravel Breeze or Fortify can later replace the middleware alias.
