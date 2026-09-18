# Architecture and extension guide

## Boundaries

MockDeck deliberately separates administration from invocation:

- `routes/web.php` contains dashboard login/logout and administration routes. Administration routes use the named `dashboard.access` middleware alias.
- `routes/mock.php` contains the root and catch-all invocation routes. Dashboard, Livewire, health, and static-asset prefixes are excluded from the catch-all expression.
- Livewire components own dashboard state and CRUD behavior. They call the same curl services used by request invocation.
- `MockInvocationController` coordinates the runtime pipeline but does not implement parsing, hashing, database matching, selection policy, or log formatting.

This keeps dashboard authentication, presentation, and response selection independent of exact-request matching.

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
- absolute HTTP/HTTPS input required, but scheme, host, and port omitted from the canonical signature;
- relative URLs, non-HTTP schemes, and embedded URL credentials rejected;
- empty paths normalized to `/`;
- query pairs sorted by decoded key, while repeated values for a key retain their original order;
- percent encoding emitted consistently with `rawurlencode`;
- configured transport/control headers removed, then remaining header names lowercased, values trimmed, and rows sorted by name/value;
- configured exclusions applied after content type is detected;
- JSON objects sorted recursively, JSON arrays kept in order;
- invalid JSON and non-JSON bodies retained as trimmed text;
- canonical sections joined as `method + path/query + headers + blank line + body`;
- SHA-256 emitted as a lowercase 64-character hex digest.

Changing any rule changes stored signatures. Origin-independent canonicalization is signature version `2`; migration `2026_09_16_000003_rebuild_origin_independent_signatures` reparses `raw_curl` and rebuilds stored canonical text/hash. The canonical-string fallback only protects against digest changes when canonical text remains compatible; it is not a substitute for migration.

## Origin-independent matching

The stored curl describes the real upstream URL, while callers invoke the path through the configured MockDeck host. `CurlNormalizer` validates the absolute input and retains only its path and normalized query in the signature. `MockCurlBuilder` replaces the upstream origin with `APP_URL` to produce a directly callable command.

`IncomingRequestFactory` always uses the received request URL. Transport headers configured in `mock.transport_header_names` are removed symmetrically from saved curls and incoming requests. There is no original-URL control header or related environment setting.

Origin independence means two curls with the same method, path/query, meaningful headers, and body have the same signature even when their upstream hosts differ. The dashboard's duplicate check prevents storing that ambiguous pair.

## Matching behavior

`EndpointMatcher` performs:

1. an enabled-only `curl_hash IN (...)` candidate query;
2. verification that each stored endpoint matches the candidate for its own selected variant;
3. ranking by priority descending, signature specificity descending, then endpoint ID ascending;
4. if no hash candidate survives, the same process over `method = ? AND normalized_curl IN (...)`.

Only the winning endpoint's responses are loaded. The fallback result carries tier `fallback`; `MockRequestLogger` raises that event to warning level. No additional warning line is written, preserving the one-event-per-request contract.

The dashboard prevents exact duplicate signatures. Overlapping broad and narrow signatures are valid; priority and specificity make their ordering explicit. Digest collisions are cryptographically improbable, but stored values are still verified with constant-time comparison.

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

The controller logs after delay and response construction so `duration_ms` includes artificial delay. Expected outcomes use info level. Fallback uses warning. An exception is logged with its class and rethrown to Laravel's exception handler. Each event includes the received mock URL and a validated caller-supplied or generated request ID; the ID is also returned as `X-Request-ID` and excluded from matching.

Logging is non-fatal: stack exceptions are ignored and `MockRequestLogger` catches a channel failure so a log destination cannot change the mock response path.

`LogTailer` spends one global `MOCK_LOG_TAIL_MAX_BYTES` budget across newest rotated files, parses valid JSON lines, ignores partial/malformed lines, and returns newest first. This bound is important; do not replace it with `file()` on a production log.

## Dashboard access

`DashboardAccess` uses a session authentication flag when `MOCK_DASHBOARD_AUTH_ENABLED=true`. Login validates configured credentials with constant-time comparison, is rate-limited, regenerates the session ID, and redirects to the intended dashboard URL. Logout invalidates the session and CSRF token.

`AppServiceProvider` registers `DashboardAccess` as Livewire persistent middleware. This is required so component update/action requests reapply the custom authorization used on the initial dashboard route.

The dashboard can display raw curl, canonical text, request bodies, and headers. Those may contain secrets. Access control and log retention should be treated as deployment requirements before exposing the service beyond a trusted development network.

## Database

Only two domain tables exist:

- `mock_endpoints` stores the original curl, one canonical representation, one digest, signature version, enabled state, priority, and exclusion flags;
- `mock_responses` stores status, header JSON, body, delay, and weight.

Request logs never enter PostgreSQL. Deleting an endpoint cascades to its responses.

## Failure semantics

| Condition | Result |
|---|---|
| URL cannot be normalized | JSON `400` with a safe diagnostic |
| No endpoint | JSON `404` with method and received mock URL |
| Endpoint has no response | JSON `500` naming the endpoint ID |
| Endpoint and response found | Configured status, headers, exact body, and bounded delay |
| Internal exception | Structured error-class log event, then normal Laravel exception handling |

These diagnostics are intentional API behavior and should be preserved in client-facing changes.

## Operational extension points

- Redis can cache digest-to-endpoint IDs, but database invalidation must occur after every dashboard save/delete.
- Telescope can be installed for development inspection independently of mock request logs.
- Promtail/Loki/Grafana can consume stdout or the mounted rotating files without changing application logging.
- An external identity-aware proxy can replace or complement the built-in single-operator dashboard credentials for larger deployments.
