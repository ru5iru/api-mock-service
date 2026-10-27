# MockDeck MCP

Phase 3 exposes a local Streamable HTTP server at **`/mcp`** with 18 tools: the original 14 P0 tools plus `peek_next_response`, `preview_template`, `preview_postman_import` and `apply_postman_import`. It builds on Phase 1’s shared services/independent credentials and Phase 2’s two-way request isolation. No external MCP hosting, identity service or agent service is required by MockDeck. Ordinary dashboard requests and verification tokens retain their existing behavior.

## Behavior baseline

`MutationCharacterizationTest` freezes endpoint create/update/delete behavior, duplicate/sample messages, credential warnings and masking, response header/seed/callback validation, callback secret retention, toast messages, and revision pre-states before extraction. Existing draft, selection, callback, matching, template, import and verification suites remain mandatory regression coverage. CI runs all `tests/Feature` and `tests/Unit` through `php artisan test` and again in the container `make validate` job; no separate opt-in test command is needed.

## Existing versioning boundary

History uses per-entity `revisions.version_number` (max + 1) and pre-change snapshots; model timestamps also exist. The current editor has no general optimistic-concurrency guard. `update_mock` now compares this same endpoint history number under the run/endpoint transaction locks before changing any fields. Response-only changes advance it too. The dashboard itself still has no general optimistic-concurrency guard.

## Scope

The server uses official `laravel/mcp` 1.0.2, compatible with this project’s PHP 8.3 and Laravel 13; Composer locks the exact dependency graph. See [Laravel MCP](https://laravel.com/docs/13.x/mcp) and the [Streamable HTTP specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports). Existing verification bearer tokens remain scoped to `/api/v1/verify/*`. MCP credentials are separate, with no automatic upgrade of those tokens. No MCP authentication middleware is applied to ordinary serving or dashboard routes.

## Shared service contract

All classes below are in `App\Services\Mutations`; none accepts a Livewire component or HTTP request. `EndpointInput`, `ResponseInput` and `SelectionInput` are immutable, explicitly typed scalar/array DTOs. They describe complete form configurations, not PATCH payloads. `McpMockConfiguration` merges omitted fields from stored configuration before constructing a DTO.

| Class | Extracted behavior |
|---|---|
| `EndpointConfigurationValidator` | Form rules, environment existence, sample/parse errors, matching configuration, duplicate signature checks, metadata readiness |
| `EndpointRequestService` | Shared cURL parsing/canonical preparation, derived names, header/query credential analysis and variable provenance, masking, body formatting and parser warning copy |
| `EndpointMutationService` | Create/update with tag/environment synchronization, rename, deletion, duplication and bulk revision orchestration |
| `ResponseConfigurationValidator` | Primary header JSON/name/value rules, status/body/template/seed/locale/delay/weight rules, template issues and fault validation/error-key mapping |
| `CallbackConfigurationValidator` | Callback field rules, header JSON, target URL, body-template validation, delays/retries/signing and preserve-secret behavior |
| `ResponseMutationService` | Scoped create/update, selection integrity, append-to-sequence, callback-only update, deletion/fallback guard and sequence renumbering with revisions |
| `ResponsePoolMutationService` | Selection draft validation, membership check, sequence ordering, global rule priorities, fallback and pool persistence with revisions |

Livewire keeps input/draft synchronization, navigation, error presentation, previews/test-delivery actions and toasts. Admin delete controllers use the same services, retaining their existing HTTP messages. Existing matching, response selection, template compilation/rendering, callback delivery, import mapping/config apply and revision restoration services are reused unchanged. Inline collection/tag creation remains organization UI, outside endpoint/response mutation extraction. Bulk UI callbacks supply their existing changes to shared revision orchestration.

Creation continues to allow response-less endpoints as the dashboard does today. Atomic `create_mock` composes endpoint/response service calls in an outer transaction and requires at least one response. Dashboard creation still permits zero responses. The authenticated tool boundary checks permission and ownership before explicit run-scoped service calls. Typed inputs are internal application contracts, not a promise to accept untrusted client-supplied secret-presence flags.

`SharedMutationServiceTest` exercises these services directly without Livewire, covering non-writing validation/preview, canonical bytes, secret analysis, scoped response updates, revision pre-states, sequence renumbering and rollback on invalid pools.

## Independent credential and permission model

`mcp_credentials` is deliberately separate from `api_tokens`. The latter has a singleton verification CLI and a fixed verification-only bearer format; extending it would risk accidentally turning verification credentials into management credentials. No migration, middleware or command changes touch that table. Existing `mdv_` tokens keep the same verification routes/behavior and are rejected by MCP authentication; new `mdm_` credentials cannot call the verification HTTP API. MCP verification tools use its internal verification service under the MCP credential, not that existing HTTP bearer gate.

Each credential stores `name`, unique SHA-256 `token_hash`, `can_read`, `can_write`, `can_verify`, `can_cleanup` booleans (all default false), `last_used_at`, `revoked_at`, and `created_at`. Plaintext is returned in a non-serializable `IssuedMcpCredential` once and is never stored in the database or model serialization. Generate at least one explicit permission. Names identify credentials for operators; use the immutable numeric ID for revocation, including when names repeat.

`McpCredentialService` generates/authenticates/authorizes/revokes. Every authorization re-reads database state; revoked credentials and already-loaded stale models fail immediately on their next check, without cache/grace period. Revocation cannot cancel an already-completed operation. `AuthenticateMcpCredential` only reads bearer credentials, never dashboard sessions. It protects `/mcp`. Every handler authorizes again at their operation boundary rather than treating earlier discovery as permanent authorization.

### P0 tool permissions

| Category / tool | Required permission |
|---|---|
| `start_test_run`, `create_mock`, `update_mock`, `apply_postman_import` | `write` |
| `list_test_runs`, `list_mocks`, `get_mock`, `validate_mock`, `get_mock_url`, `preview_template`, `preview_postman_import`, `peek_next_response`, `explain_match` | `read` |
| `get_unmatched_requests`, `get_calls`, `assert_calls` | `verify` |
| `reset_test_run` | `cleanup` |
| `finish_test_run`, including its delete option | `cleanup` |

Permissions do not imply each other. To complete the full create/inspect/assert/cleanup workflow, grant all four. Finishing closes a run even without deletion, so it also requires `cleanup`. No extra `write` permission is required for runtime reset/cleanup; ownership checks remain mandatory for every tool. Discovery/initialization requires a valid credential; discovery lists all tools; execution enforces the grants independently. `McpToolPermissions::REQUIRED` is a tested, fail-closed contract; unknown tools do not get a default permission. All 18 tools below are callable.

### Rate-limiting convention

Existing routes already use Laravel `throttle` middleware. The `/mcp` route applies, in order, `throttle:mcp-auth`, `AuthenticateMcpCredential`, then `throttle:mcp`, before parameter binding/tool execution. The first limiter bounds authentication attempts at 30 requests/minute/IP; the second bounds authenticated requests at 60/minute/credential. Limits are configured in `config/mcp.php`. Every request passes the IP guard, so agents behind the same proxy/NAT also share that guard. Configure trusted proxy handling correctly before external exposure. These named limiters do not alter ordinary mock or dashboard routes.

`McpCredentialTest` checks one-time reveal/hash-only storage, stale-model revocation, 401 versus 403, session independence, verification/MCP token separation, unchanged verification schema, the complete permission map and named limiter keys/defaults.

## Credential lifecycle (CLI)

Apply migrations through normal `make up` deployment before issuing credentials. Then connect a client to `/mcp` with the generated bearer.

Generate with explicit grants (there is no default full-access token):

```bash
make artisan ARGS="mockdeck:mcp-credential:generate ci-agent --permission=read --permission=write --permission=verify --permission=cleanup"
make artisan ARGS="mockdeck:mcp-credential:list"
make artisan ARGS="mockdeck:mcp-credential:revoke 1"
```

For a host-PHP deployment the same commands are `php artisan mockdeck:mcp-credential:generate ...`, `php artisan mockdeck:mcp-credential:list`, and `php artisan mockdeck:mcp-credential:revoke ID`. Generation prints the `mdm_` bearer exactly once; store it immediately in the agent's secret configuration. Listing shows ID/name/explicit grants/last-used/revoked status, never the bearer or hash. Revoke uses a numeric ID and is idempotent for an existing credential. Unknown IDs fail clearly. Revoked rows are retained as audit identities.

Rotation: generate a new credential with only the required permissions, update the client's secret, then explicitly revoke the old ID. Each revocation takes effect immediately; there is no post-revocation grace period. If an old bearer is compromised, revoke it first rather than waiting for a client update. Losing a bearer requires a replacement; neither listing nor the database can recover it. These commands never modify the separate Wave 2 verification token.

`McpCredentialCommandTest` covers show-once output, safe metadata listing, invalid grants, revocation and explicit rotation. The existing Make `artisan` target is reused without adding a second command interface.

## Invocation audit foundation

`mcp_invocations` contains `id`, `credential_id` (FK to `mcp_credentials`, deletion restricted), nullable `tool_name`, nullable `target`, nullable public UUID `run_id` (intentionally not a foreign key, so audit metadata survives cleanup), `result` (`success`/`error`), nullable `error_code`, UUID `correlation_id`, and `created_at`. Revocation preserves the actor row. Every authenticated tool call except `preview_template` and `preview_postman_import` writes a row, including cached retries and rejected tool envelopes. Both previews have an explicit no-persistence contract: successful, invalid and SDK-rejected preview calls do not write invocation rows or credential last-used timestamps. Initialization/discovery and every other tool retain their existing metadata behavior; authorization and rate limiting still apply to previews. No request payload column exists. Unauthenticated requests without an identifiable credential use separate redacted operational diagnostics rather than a fabricated audit actor.

`McpAuditRedactor` reuses `RequestCredentialPolicy`'s configurable header/query detection and masking plus `EnvironmentVariableWriter::sensitiveName`. It recursively redacts named secrets, header/variable entry values, JSON-encoded metadata, credential-shaped bearer strings and sensitive query parameters; depth/string output is bounded. The `McpInvocation` target setter also applies redaction. Do not put raw request/response bodies or arbitrary free-form payloads into `target`: use compact identifiers. No heuristic can identify every unlabeled secret. Writers apply the helper before persistence, allowlist audit metadata, and must not bypass the model with unredacted query-builder inserts.

cURL text in output is parsed with the shared parser and formatter so custom sensitive header names and named JSON body fields receive the same recursive redaction. Form-style `name=value` strings use that same sensitive-name policy, retaining other fields. Unparseable cURL text is withheld. This projection changes output, cached results and run-history snapshots only; saved request bytes and matching signatures are unchanged. Opaque body text and path segments can still contain secrets.

Credentials and invocation history are operational/security state, excluded from native configuration export/import and revision snapshots. No export-format bump is required. New MCP runtime tables are migrated normally. `McpAuditContractTest` covers schema, no automatic writes, nested/custom secret detection, metadata bounds, enum codes and export exclusion.

## Fixed error-code taxonomy

`App\Services\Mcp\McpErrorCode` is the stable code vocabulary. It is distinct from MCP protocol/transport error codes; handlers expose it in structured application results.

| Code | Meaning |
|---|---|
| `VALIDATION_ERROR` | Input/configuration failed domain validation; field-level detail may be supplied after redaction. |
| `AUTH_ERROR` | Missing, malformed, unknown or revoked bearer credential. Authentication middleware returns HTTP 401. |
| `FORBIDDEN` | Authenticated credential lacks the required permission. Authentication middleware returns HTTP 403. |
| `CONFLICT` | Configuration changed since the caller's expected revision/version or another configuration conflict prevents the operation. |
| `RUN_NOT_FOUND` | Referenced test run is not available to this caller. Other credentials’ runs are indistinguishable from missing runs. |
| `RUN_FINISHED` | Requested operation requires an active run but the run is already finished. |
| `ASSERTION_FAILED` | Complete available evidence demonstrates the expectation was not met. |
| `INCONCLUSIVE_EVIDENCE` | Evidence is incomplete or cannot evaluate the requested predicate; do not report a false exact count. |
| `IDEMPOTENCY_CONFLICT` | An idempotency key was already used with a different operation/payload. |
| `NOT_FOUND` | An accessible endpoint/response or other target does not exist. |

Authentication failure is HTTP 401; missing tool permission is a structured FORBIDDEN result with `isError`. Domain errors remain separate from JSON-RPC parse/method/parameter errors. Existing dashboard validation messages and verification API response shapes remain unchanged.

## Compatibility verification

The one-time issuance wrapper also rejects cloning, serialization and debug disclosure of its bearer. Migration rollback/reapply tests compare existing endpoints, responses, verification tokens and runtime counters byte-for-byte. Eleven standard/custom error cases were additionally compared against the pre-extraction components and matched exactly; they are retained in characterization coverage. The full existing application suite remains the broader behavior baseline. Phase 3 adds real protocol/client, concurrent mutation and secret-boundary tests to this baseline.

## Phase 2: test-run ownership

`test_runs` binds an unguessable UUID `public_id` to its owning MCP credential and a fixed environment. The internal numeric ID is never a routing identifier. `TestRunService::create` requires write permission; finishing with either cleanup option requires cleanup. Ownership is checked before finishing; another credential cannot finish a run. Revocation remains immediate. Phase 3 exposes lifecycle management through the authenticated MCP transport.

Run-owned endpoints have `test_run_id`; ordinary endpoints retain NULL. Default endpoint/response queries, dashboard bindings, imports and portable exports only see ordinary configuration. Internal runtime code must explicitly opt into a run scope. Phase 3 explicitly records redacted run configuration revisions for MCP writes; automatic runtime recording remains disabled. The portable format is unchanged.

Call-state uniqueness uses two partial unique indexes: `(endpoint_id, environment_id)` for NULL run IDs and `(endpoint_id, environment_id, test_run_id)` otherwise. A plain nullable composite unique constraint would allow duplicate ordinary state rows in PostgreSQL and SQLite; preserving first-call concurrency requires the NULL-specific index. Environment deletion is restricted while a run references it. Rollback removes ephemeral run mocks/state before restoring the previous schema.

The upgrade preflight rejects existing endpoint paths under `/_mockdeck` or `/mcp` (including encoded spellings), or a configured `X-MockDeck-Test-Run` header. It reports the offending endpoint ID before adding tables/columns; rename that configuration using the previous version and retry. These namespaces become reserved; they are not silently reassigned.

## Routing isolated HTTP requests

Tools create and manage runs; application traffic uses the same Phase 2 routing layer. A caller holding an active run's public UUID can use either mechanism:

```bash
# Use when the application can attach a header. The URL stays unchanged.
curl http://localhost:18473/users/42 -H 'X-MockDeck-Test-Run: <public UUID>'

# Use when only the application's API base URL can be changed.
curl http://localhost:18473/_mockdeck/run/<public UUID>/users/42
```

Both mechanisms invoke `ResolveTestRun` before the same `EndpointMatcher`, using an explicit `RequestScope`. Header routing removes `X-MockDeck-Test-Run` before matching/context capture. Prefix routing removes only `/_mockdeck/run/<public UUID>`; method, remaining raw path/encoding, query, body and other headers are retained. Routing metadata is never hashed and does not bump signature versions. Matching algorithms, precedence, response selection modes and fault behavior are unchanged. The instance-wide active environment is never switched: eligibility, response templates, callback scheduling/delivery, logs and call counters use the run's bound environment explicitly.

Isolation is a two-way wall: run traffic considers only its own run's endpoints, and ordinary traffic considers only NULL-run endpoints. A miss never falls back to ordinary mocks or another run. Identical signatures can coexist across these scopes. Invalid/unknown/finished runs or runs owned by revoked credentials return 404 with `test_run_unavailable`. Conflicting header/prefix IDs return 400; providing the same ID through both is allowed. A run UUID is a routing capability, not a management credential: keep it private. Requests do not require the MCP bearer token, so applications that can only change a base URL remain supported. Running requests already admitted before finish/revocation may complete; later requests and queued callback jobs fail closed.

`/_mockdeck` and all descendants, plus `/mcp` and descendants, are application-owned namespaces. `/mcp` authenticates before protocol handling (401 without a credential); descendants remain reserved and never fall through to mocks. Nginx forwards these paths normally to Laravel; no duplicate matcher or proxy rewrite is involved. The shared Nginx body ceiling is 25 MiB to carry two bounded Postman files with JSON-RPC escaping; the FastCGI timeout remains 60 seconds. Per-file and non-import MCP limits remain enforced in Laravel. The `/api/v1/verify/*` API remains ordinary-only, including reset-all: an old verification token cannot inspect or reset run state. Routing reads the live request and run row, independently of request-log retention.

## Isolation regression coverage

`ConcurrentRunRoutingTest` launches eight PHP HTTP server workers and 24 barrier-synchronized TCP clients. Two runs and ordinary traffic all use the same POST path/query/JSON body and strict retained headers. Header and prefix mechanisms are mixed within each run. The test asserts each scope gets only its own responses, every sequence position 0–7 exactly once, and separate counts/digests. It runs in the normal full PHPUnit suite using a disposable SQLite file, plus the container CI job against the dedicated disposable PostgreSQL database `mockdeck_run_isolation_test`. The PostgreSQL mode refuses any other database name and requires `MOCKDECK_RUN_CONCURRENCY_POSTGRES=true`; never point it at a working instance.

`TestRunRoutingTest`, `TestRunOwnershipTest` and `TestRunCompatibilityTest` cover fail-closed routing, bound template/callback environments, global-setting preservation, dashboard hiding, old verification-token/reset isolation, nullable-state uniqueness, ownership, migration safety/collision handling, revision refusal and byte-identical ordinary export output. Existing Phase 1 characterization and serving tests remain mandatory. Phase 3 reuses the existing History component in a read-only run view.

## Phase 3 transport

The `/mcp` POST endpoint now uses official `laravel/mcp` 1.0.2, locked by Composer. Streamable HTTP JSON responses support the requested `initialize` / `tools/list` / `tools/call` flow for protocol 2025-11-25, plus the library's current stateless discovery protocol 2026-07-28. Resources and Prompts are outside this phase and are not advertised. No external service is contacted at initialization. Each HTTP request must carry a locally issued `Authorization: Bearer mdm_…`; dashboard cookies and verification `mdv_` tokens never suffice. The existing `mcp-auth` and `mcp` named throttles apply. Non-import input is bounded to 1 MiB; Postman tools have a separate envelope allowance for two bounded files (see below). Browser Origin is checked. GET/DELETE return 405 because this server needs no persistent SSE/session channel. Domain tool failures use structured results plus `isError`, distinct from JSON-RPC protocol failures.

### Atomic creation and idempotency foundations

`mcp_idempotency_keys` retains redacted create results for 24 hours, scoped by credential, run, tool and key. Full input is canonicalized (object key order ignored, list order preserved) and hashed; different payload reuse produces `IDEMPOTENCY_CONFLICT`. The run-row lock serializes creates/updates and covers both fixture writes and cache insertion. Expired keys are removed opportunistically; the prune command is documented below. Invalid operations roll back completely. Shared endpoint/response mutation services now accept an explicit optional run; their default remains ordinary-only. The existing verification controller and MCP share `RuntimeStateReset`.

Phase 3 adds optional run ownership on revisions. Explicitly authorized run mutation code can record through `RevisionManager` with the existing per-entity `version_number`; automatic runtime revision recording remains disabled. Run snapshots are redacted. Ordinary revisions and portable format 1.5 are unchanged. This deliberately supersedes Phase 2's blanket exclusion for agent configuration mutations, while exports still exclude run configuration and runtime counters never enter configuration snapshots.

### P0 read contracts

MCP reads are restricted to the credential’s own runs, including finished runs. `read` does not grant access to ordinary dashboard mocks. `list_test_runs` requires `read`; unmatched request and call evidence tools require `verify`. All tool results carry `ok`, `correlation_id`, and either structured `data` or `error` (stable code/message/details); failed tools also set MCP `isError`. Discovery publishes bounded input schemas and safety annotations. Tool lists use the SDK’s default 15-item pages; clients must follow `nextCursor` to discover all 18 tools (the tested PHP client does so automatically).

`get_calls` separates the exact since-reset total from the 20-entry evidence window. `explain_match` calls the actual scoped matcher without invoking selection, counters or callbacks. It accepts either curl text or a retained unmatched request UUID. The latter window is 20 requests per run, 24 hours, bodies limited to 64 KiB and headers to 64 entries/4096 bytes each. Captured evidence is encrypted using APP_KEY; truncated evidence cannot claim an exact diagnosis. Expired IDs return NOT_FOUND. Differences omit field values, and exclusions are applied before comparison. Raw arguments are never written to the invocation audit; only scoped identifiers, outcome and correlation are stored.

`docs/MCP_TOOLS.json` is the discovery schema reference. All new PHP feature tests run through the existing PHPUnit suite in CI.

### P0 writes and runtime history

`start_test_run` requires write and binds an existing Environment by name, defaulting to the active Environment without switching it. `create_mock` accepts `configuration.curl` or `configuration.request`, 1–50 responses and a required idempotency key. It validates and creates everything in one transaction; duplicate canonical signatures within the run return CONFLICT naming the existing mock. Different runs may reuse the same shape.

`update_mock` takes explicit endpoint patches and response add/update/remove lists. Its required `expected_version` is the existing endpoint revision `version_number`; the owning run and endpoint are locked before comparison. All response-only changes advance that same version. No-op patches preserve it. Mutations use the shared dashboard DTOs/validators and support Weighted, Sequence and Rule-based selection. Omitting selection fields on creation keeps the original Weighted defaults; omitted update fields preserve the existing mode and settings.

`assert_calls` delegates to the same predicate validation and evidence evaluator as the verification API. Definite failure returns ASSERTION_FAILED; insufficient evidence returns INCONCLUSIVE_EVIDENCE with count bounds and missing-call counts. Neither changes runtime state.

`reset_test_run` clears only owned-run counters/sequence/evidence and records configuration-preserving endpoint revisions. `finish_test_run` requires cleanup for either option, returns a persisted final summary and repeats return that summary without additional deletion. Delete records scoped endpoint revisions before deletion; other runs and ordinary mocks remain untouched. MCP explicitly opts into run revisions; automatic runtime edits still cannot create ordinary configuration history, and portable exports remain run-free.

### Scoped History and tested client boundary

The `history_url` returned for a mock opens `/dashboard/test-runs/{publicId}/history` behind normal Operator authentication. It reuses the Version History timeline/diff component, scoped by run, and permits comparison rather than restoration of redacted runtime snapshots. Cleanup retains those entries. No new visual pattern or token is introduced. Known secret Environment values are removed from scoped snapshots as well as tool output.

`McpClientIntegrationTest` uses the actual `laravel/mcp` 1.0.2 PHP client with `withToken`, pins protocol `2025-11-25`, and exercises initialization, discovery, tools, both routing forms, assertions and cleanup over HTTP and verified HTTPS. The TLS terminator in that test is disposable test infrastructure, not the production Nginx stack. Discovery is also checked with the SDK’s `2026-07-28` protocol. This is the tested client/auth combination, not a claim of compatibility with every agent client. SDK-rejected tool envelopes are audited as errors without retaining their untrusted arguments.

### Concurrent mutation and offline checks

`ConcurrentMcpMutationTest` launches eight HTTP workers and barrier-synchronized official SDK clients: four identical creates execute once and two updates at the same version produce one success/one CONFLICT. `ConcurrentRunRoutingTest` separately proves two runs and ordinary traffic coexist under 24 simultaneous calls with independent sequence state.

`make mcp-test` runs the MCP suite through the existing Compose test convention. `make mcp-offline-test` runs the actual HTTP/trusted-TLS client workflow in `docker run --network none`, using only container loopback and a disposable SQLite file. CI runs that check in addition to the full suite. No Python, certificate CLI, schema download, SaaS MCP host or external credential service is required by the client test: TLS certificates are generated locally by PHP OpenSSL. The runtime needs no external services beyond its configured database/optional callback targets.

Browser Origin must match APP_URL or an explicitly configured `mcp.allowed_origins` entry. Native clients without Origin still authenticate normally. Production TLS, including shared-port HTTP/HTTPS Nginx setup, follows [HTTPS.md](HTTPS.md); local test TLS is not a substitute for that deployment check.

### SQLite and retention maintenance

SQLite cannot honor `FOR UPDATE`; the tool transaction acquires its writer reservation with a no-op credential-row update **before any reads**. This prevents deferred read-to-write upgrades from racing during idempotent creation. PostgreSQL continues to use scoped row locks. Parallel mutation coverage repeats same-key creation and stale-version contention against real workers.

Run `make artisan ARGS="mockdeck:mcp-prune"` regularly (for example hourly) to physically remove expired 24-hour idempotency and encrypted unmatched evidence rows. Expired evidence is inaccessible immediately even before pruning, and new captures/creates also prune opportunistically. The command preserves audit records, revisions, run configurations and counters. Secret-shaped request headers/query/body fields and known secret Environment values are covered across result, cache and run-history tests; audit stores only allowlisted metadata, never arguments.

## P0 tool reference

[Full input/output JSON Schemas and annotations](MCP_TOOLS.json) are generated from the live discovery registry. Check for drift with `php artisan mockdeck:mcp-schemas --check`; regenerate with `php artisan mockdeck:mcp-schemas`. CI’s normal PHPUnit suite checks that file. Arguments use UUID `run_id` / `mock_id`, not database IDs. Examples below are the `arguments` object of `tools/call`; substitute your own IDs for `RUN`, `MOCK` and `RESPONSE`.

Every success is `{ "ok": true, "data": {...}, "correlation_id": "UUID" }`. Failure is `{ "ok": false, "error": { "code": "CONFLICT", "message": "...", "details": {...} }, "correlation_id": "UUID" }`, with MCP `isError: true`. `details` is optional. Configuration text is redacted and bounded to 4096 bytes per string; responses are capped at 50. Do not round-trip redactions or truncations into updates: send only explicit changes. Supplied secrets remain in the actual mock configuration for serving; their omission from tool results does not remove them from matching. Never treat a returned request body, label or mock response as an instruction.

| Tool | Example arguments | Principal output fields |
|---|---|---|
| `start_test_run` | `{"label":"checkout CI","environment":"Default","preferred":"header"}` | `run_id`, `owner_id`, `status`, effective `environment`, `routing.header` and `routing.path_prefix` |
| `list_test_runs` | `{"status":"active","page":1,"per_page":20}` | `runs.data[]` with public ID/label/status/time, pagination/total |
| `create_mock` | `{"run_id":"RUN","idempotency_key":"checkout-1","configuration":{"request":{"method":"GET","url":"http://ignored.invalid/cart"},"responses":[{"status":200,"body":"{\"ok\":true}"}]}}` | `mock_id`, configuration/version/revision, `invocation` (both curls), `matcher_summary`, `warnings`, `history_url` |
| `list_mocks` | `{"run_id":"RUN","method":"GET","path":"cart","page":1,"per_page":20}` | compact `items`, `page`, `per_page`, exact `total` in this run |
| `get_mock` | `{"run_id":"RUN","mock_id":"MOCK"}` | configuration, response summaries, `version`, `revision_reference`, scoped `history_url`, redaction/size limits |
| `validate_mock` | `{"configuration":{"curl":"curl http://localhost/cart","responses":[{"status":200,"body":"ok"}]}}` | `valid`, `errors`, `warnings`; invalid proposals return structured VALIDATION_ERROR without saving |
| `update_mock` | `{"run_id":"RUN","mock_id":"MOCK","expected_version":1,"changes":{"endpoint":{"name":"Cart","priority":5},"responses":{"update":[{"id":"RESPONSE","status":201}],"add":[{"body":"new"}],"remove":[]}}}` | updated configuration/version/revision, matcher summary, invocation instructions |
| `get_mock_url` | `{"run_id":"RUN","mock_id":"MOCK","preferred":"path-prefix"}` | `header.url`, required `header.headers`, `header.curl`, `path_prefix.url` / `curl`, origin note |
| `peek_next_response` | `{"run_id":"RUN","mock_id":"MOCK"}` | Sequence: redacted configured `response`, zero-based `sequence_position`, one-based `next_position` / `position_label`, `response_count`, `reason`; other modes: `applicable: false` |
| `preview_postman_import` | `{"run_id":"RUN","collection":"RAW JSON","environment":"OPTIONAL RAW JSON","mode":"create-only"}` | dashboard-shaped run-scoped plan; no persistence |
| `apply_postman_import` | `{"run_id":"RUN","collection":"RAW JSON","idempotency_key":"import-1"}` | summary, applied plan, changed mock IDs, history references and both invocation forms |
| `preview_template` | `{"run_id":"RUN","template":"{\"id\":\"$string.uuid\"}","locale":"en","seed":42}` | rendered typed `output`, `bytes`, `render_ms`, dashboard-shaped `issues`, `synthetic_context`, `sample_notice`; requires only `read` and persists no preview/configuration/auth-audit metadata |
| `explain_match` | `{"run_id":"RUN","curl":"curl http://localhost/cart?x=wrong"}` or `{"run_id":"RUN","log_entry_id":"LOG_UUID"}` | `selected`, bounded ranked `candidates`, per-field differences/exclusions without values, candidate-window completeness |
| `get_unmatched_requests` | `{"run_id":"RUN","page":1,"per_page":20}` | retained stable IDs/time/completeness/request metadata, `window` capacity/TTL/truncation limits |
| `get_calls` | `{"run_id":"RUN","mock_id":"MOCK","limit":20}` | exact `total_match_count`, `last_matched_at`, sequence position if applicable, `recent_calls`, evidence `window` |
| `assert_calls` | `{"run_id":"RUN","mock_id":"MOCK","expect_count":2,"comparator":"equals","matching":[{"field_type":"query","field_name":"cart","operator":"equals","value":"42"}]}` | `passed`, exact-or-null `actual_count`, CI-readable `message`, `verdict`, count bounds/window; failure details contain the same evidence fields |
| `reset_test_run` | `{"run_id":"RUN"}` | `reset`, `state_rows_reset`, `configuration_preserved`; verification evidence cleared, unmatched-request window unaffected |
| `finish_test_run` | `{"run_id":"RUN","cleanup":"delete_mocks"}` | persisted `status`, original cleanup choice, mock/count totals and finish time; a retry returns this same summary |

`configuration` accepts `name`, `enabled`, `priority`, `curl` **or** `request`, `matching`, existing `collection_id`, existing tag IDs in `tags`, and `responses`. A structured request has `method`, `url`, header-name/value object and text `body`. Hosts are interchangeable for matching. `matching` uses the existing `exclude_cookies`, `exclude_auth` (default true), `exclude_headers`, `excluded_query_params`, `excluded_headers`, `path_pattern_enabled` fields. Wildcards retain the existing `{name}` whole-segment syntax. MCP tools do not execute curl commands.

Each response accepts `status`, header-name/value object, `body`, `body_mode` (`static`/`template`), `weight` (default 1), fixed `delay_ms`, `locale`, `seed_mode` (`random`/`fixed`/`request`) and optional integer `seed`. In Template mode `body` is the existing MockDeck template source; the same compiler, context resolver and introspected Faker method catalog validate it. Sequence/rule and primary fault fields are described below. Callback editing remains outside the MCP schema. Existing serving behavior and configuration of those features are preserved by partial updates.

`changes.endpoint` is a patch; `changes.responses` has `add`, `update` (each with a response UUID), `remove` (UUID list). Unknown fields, cross-mock response IDs and repeated response operations are rejected. Empty endpoint fields are not implicitly overwritten. Updates and reset/delete revisions share `revisions.version_number`; there is no second concurrency counter. A cached create may be replayed for 24 hours even after finish: it returns the original redacted result and never resurrects mocks or reopens routing. A new create in a finished run returns RUN_FINISHED.

Predicates are the existing verification/rule grammar: `field_type` is `header`, `query` or `body_json_path`; `operator` is `equals`, `contains`, `regex` or `exists`. Non-exists operators require a string `value`; regex uses PHP delimiters such as `/pattern/i`. Comparators are exactly `equals`, `at_least`, `at_most`. Unfiltered assertions always use the exact counter. Filtered assertions use existing three-valued evidence evaluation: provable bounds can establish a result; otherwise the tool returns INCONCLUSIVE_EVIDENCE with the count outside the retained window, never a false exact zero.

## Read-only template preview

`preview_template` always requires **read only**, including when referencing a saved response. It uses `ResponseTemplateEngine::preview(template, locale, seedMode, seed, endpoint, environment)` directly, with the same compiler, introspected Faker catalog, renderer, secret-preview guard and synthetic request/path context as the dashboard. It never selects or serves a response, advances counters, creates revisions, applies faults/delays or dispatches callbacks.

| Input | Contract |
| --- | --- |
| `run_id` | Required owned public run UUID; supplies the bound Environment without switching the global active Environment. Finished runs can still be inspected. |
| `template` | Raw template source, at most 262144 characters and subject to the engine's existing byte/depth/node/output limits. Mutually exclusive with saved references. |
| `mock_id` + `response_id` | Both required for reference mode. The mock must belong to this run and the response to that mock, in Template mode. Ordinary/foreign mocks and responses are inaccessible. |
| `locale` | Optional configured locale; defaults to the saved response's locale in reference mode, otherwise `en`. |
| `seed` | Optional integer (fixed preview) or `"random"`; omitted means random, even for a saved response. Does not alter the saved seed settings. |

Success returns a typed `output` (objects, arrays and scalar types retained), `bytes` and `render_ms`, plus the dashboard's exact `issues` entries: `severity`, `code`, `path`, `line`, `col`, `message`, `suggestion`. The existing `synthetic_context` flag indicates context-dependent rendering. `sample_notice` reuses the dashboard wording verbatim: **Request-context values in this preview are synthetic samples, not captured live traffic.** The notice is also returned for context-free Faker samples, as it is displayed by the dashboard. No matched-request data is read; pattern references receive the same `sample_id`-style path captures as editor preview.

Invalid templates return MCP `isError` with `VALIDATION_ERROR` and preview fields under `error.details`, including unchanged dashboard-shaped `issues` and null output. Unknown methods retain `UNKNOWN_METHOD` with the same location/message/suggestion. Secret Environment references retain `SECRET_PREVIEW_HIDDEN`; they are never rendered or exposed. Other secret-shaped literal sample values are redacted through the shared MCP output policy, including nested objects. Standard MCP depth/string redaction bounds still apply; metrics describe the engine's render before output redaction.

Raw-template example (`tools/call` arguments):

```json
{"run_id":"RUN","template":"{\"id\":\"$string.uuid\",\"name\":\"$person.fullName\",\"env\":\"{{env.NAME}}\"}","locale":"en","seed":42}
```

Saved-response example:

```json
{"run_id":"RUN","mock_id":"MOCK","response_id":"RESPONSE","seed":"random"}
```

### No-persistence boundary and evidence

The handler uses the uncached preview path, never `renderResponse`'s saved-template cache. Preview is the deliberate exception to Phase 3's general invocation audit rule: it skips the SQLite writer reservation, run write lock/transaction, invocation audit and credential last-used update. This applies to validation failures and SDK-rejected preview input too. Bearer authentication, fresh revocation/read-permission checks, run/response ownership, Origin/input bounds and normal infrastructure rate limiting remain enforced. Every other tool still writes its usual audit/usage metadata. Server diagnostics and infrastructure rate-limit cache counters remain outside preview data; with a database-backed cache those cache counters may change. No template source, rendered sample, configuration, call state, revision, audit row or credential timestamp is stored by the preview operation.

`McpTemplatePreviewTest` takes complete serialized database-table snapshots before/after raw and referenced previews, including the HTTP authentication/protocol path and error cases. With the test array-cache backend it also observes SQL and asserts zero INSERT/UPDATE/DELETE/REPLACE statements. It confirms a credential lacking write/verify/cleanup can preview its own saved response, exact diagnostic equality with Livewire, bound-environment/path samples, unchanged active environment and secret protection. The official PHP client tests raw/reference previews and structured errors over HTTP and verified HTTPS. Existing full PHPUnit and schema-drift CI checks include these tests automatically.

## Primary response fault configuration

MCP exposes Wave 2's existing primary-response fault engine through the same response objects extended for selection modes. The fields work in `create_mock.configuration.responses`, `update_mock.changes.responses.add/update` and either proposal form of `validate_mock`. Existing write/read permissions, run ownership, atomic validation, optimistic versions and idempotency remain unchanged; no new tools, tables, fault types or serving logic are added.

| Response field | Accepted input | New-response default |
| --- | --- | --- |
| `fault_enabled` | Boolean | `false` |
| `fault_type` | `delay`, `malformed_body`, `truncated_body`, `timeout` only | `delay` |
| `fault_delay_ms_min` | Integer, 0–120000 milliseconds | `0` |
| `fault_delay_ms_max` | Integer, 0–120000 and at least the minimum, or `null` for a fixed delay equal to the minimum | `null` |
| `fault_probability` | Integer percentage, 0–100; 0 never applies, 100 always applies | `100` |

Delay bounds apply to Delay and Timeout and use the existing fixed-or-range behavior. `timeout` is a **bounded long-delay approximation**, not an indefinite connection hang; supply positive bounds for the desired delay and account for client/proxy timeouts. `connection_reset` was not shipped and is rejected with the normal structured `VALIDATION_ERROR`, even if `fault_enabled` is false. It is never silently ignored or substituted. The discovery enum and delay limit are sourced from `FaultConfigurationValidator::TYPES` / `MAX_DELAY_MS`, and all dry runs/writes ultimately use that existing validator through `ResponseConfigurationValidator`.

Malformed body requires a JSON/XML Content-Type (including supported `+json`/`+xml` variants); incompatible formats return the existing validation message. Truncated body cuts the configured/rendered body to half its byte length. These effects preserve the declared Content-Type and run after template rendering. Faults compose with Weighted, Sequence and Rule-based selection: a faulted call still increments the run's exact match counter and enters its retained evidence; the request log retains `fault_applied` and `fault_delay_ms` markers. This is separate from the ordinary `delay_ms` field. `get_mock`, create/update results and `peek_next_response` report the configured fault fields; peeking never applies a fault or sleeps.

Omitting all fault fields on creation retains the existing non-faulty defaults. Omitting them on update preserves any existing fault configuration, including disabled flags and nullable bounds; send `fault_enabled: false` explicitly to disable a configured fault. An explicit null maximum clears a range back to a fixed delay. Ordinary dashboard-configured faults, native format 1.5 and portable/revision semantics are unchanged.

Create a delayed response (`tools/call` arguments):

```json
{"run_id":"RUN","idempotency_key":"delay-fixture","configuration":{"request":{"url":"http://ignored.invalid/slow"},"responses":[{"status":200,"body":"{\"ok\":true}","fault_enabled":true,"fault_type":"delay","fault_delay_ms_min":100,"fault_delay_ms_max":250,"fault_probability":100}]}}
```

Dry-run an update using `validate_mock`, then send the same `changes` to `update_mock` with the current `expected_version`:

```json
{"run_id":"RUN","mock_id":"MOCK","changes":{"responses":{"update":[{"id":"RESPONSE","fault_enabled":true,"fault_type":"delay","fault_delay_ms_min":100,"fault_delay_ms_max":null,"fault_probability":100}]}}}
```

Fault-specific feature tests cover measured delay and call/log evidence, all shipped types, unsupported-type and range/content-type validation parity, omitted-field compatibility, nullable partial patches, composition with sequence/rules, and faults configured through Livewire. Schema-drift tests cover the shared enum/bounds on all input variants and configuration readback. The official PHP MCP client exercises fault updates and structured rejection over HTTP and verified HTTPS; these tests run in the existing full PHPUnit CI jobs without a new workflow.

## Sequence and rule-based response selection

The additive selection fields call Wave 1's existing `SelectionConfigurationValidator` and serving strategies. `create_mock`, `update_mock` and `validate_mock` use the same final-pool validation through `ResponsePoolMutationService`; no second selection algorithm or condition grammar exists. Existing credentials, run isolation, counters and the native portable format are unchanged.

| Location | Field | Values and behavior |
| --- | --- | --- |
| `configuration` / `changes.endpoint` | `selection_mode` | `weighted` (creation default), `sequence`, `rule`. Omitted on update preserves the current mode. |
| Same endpoint object | `sequence_on_exhaust` | Sequence only: `repeat_last` (default), `loop`, `not_found`. |
| Response object | `sequence_order` | Sequence only: zero-based unique consecutive positions `0..N-1`. New sequence responses default to their final input-list index; existing positions are preserved when omitted. Explicitly close gaps when removing/reordering. |
| Response object | `rule_conditions` | Rule only: up to 32 existing condition objects, each with `field_type`, `field_name`, `operator`, optional `value`, and required non-negative integer `priority`. Persisted in the existing `response_rules` relation. |
| Response object | `is_default` | Rule only: exactly one final response must be `true`; new responses default to false, existing flags are preserved when omitted. |

Conditions use `header`, `query`, `body_json_path` and `equals`, `contains`, `regex`, `exists` verbatim. Non-exists operators require a string value; Exists permits omitted/null value. All conditions on a response must match. Existing priority, then weight/UUID tie-breaking chooses among matching responses; otherwise the single default serves. The signature must still match first: exclude a varying header/query field from signature matching when using it to choose among rules. Sensitive condition values are redacted using the same credential policy as other results/history.

Only explicitly supplied mode-specific fields are rejected outside their mode, including an empty `rule_conditions` or false `is_default` in Weighted mode. Inactive stored fields are managed internally. Validation errors use the existing structured `VALIDATION_ERROR` envelope, including the original “exactly one Default / fallback response” message. Validation and writes inspect the **final** pool, so replacing the fallback or swapping sequence positions succeeds in one transaction. Invalid edits leave configuration, version and runtime counters untouched; selection edits never reset the run's cursor. Use `reset_test_run` when the next scenario should start at position zero.

Create a sequence (`tools/call` arguments):

```json
{"run_id":"RUN","idempotency_key":"retry-sequence","configuration":{"request":{"url":"http://ignored.invalid/retry"},"selection_mode":"sequence","sequence_on_exhaust":"repeat_last","responses":[{"status":503,"sequence_order":0},{"status":503,"sequence_order":1},{"status":200,"sequence_order":2}]}}
```

Create rule selection with a varying header excluded from the signature:

```json
{"run_id":"RUN","idempotency_key":"header-rule","configuration":{"request":{"url":"http://ignored.invalid/rule"},"matching":{"excluded_headers":["x-mode"]},"selection_mode":"rule","responses":[{"status":201,"rule_conditions":[{"field_type":"header","field_name":"X-Mode","operator":"equals","value":"yes","priority":0}]},{"status":200,"is_default":true}]}}
```

Dry-run an update with `validate_mock` using the **same** `changes` object as `update_mock`, plus owned `run_id` and `mock_id`. Provide exactly one of `configuration` or `changes`. A `configuration.responses` list is a complete proposed pool (existing members can carry their UUID `id`); an omitted list preserves the existing pool. A `changes.responses` patch preserves omitted members and settings, and accepts the usual add/update/remove operations. Validation does not save, record revisions or create call-state rows.

```json
{"run_id":"RUN","mock_id":"MOCK","changes":{"responses":{"update":[{"id":"OLD_DEFAULT","is_default":false},{"id":"NEW_DEFAULT","is_default":true}]}}}
```

### Peek without consuming a sequence

`peek_next_response` requires only `read` and owned `run_id` / `mock_id`. It delegates to `ResponseSelectionService::preview()` with the run's bound environment. It never calls the consuming selector and never inserts or updates `endpoint_call_state`: an unused sequence stays without a state row. Auditing still records the MCP invocation, as for other read tools.

For Sequence it returns `applicable: true`, the zero-based stored cursor, the redacted configured response, and a one-based upcoming position such as `"2 of 3"`. The configured template source is returned without rendering; no random selection, callbacks, delays or fault injection execute. Exhaustion uses the existing Repeat last / Loop / Not found strategy. Repeat last reports the final response; Loop reports the wrapped position. Not found returns `response: null`, `position_label: null`, `reason: "sequence_exhausted"`. The raw cursor remains unbounded as in serving. The result is a snapshot at read time; a concurrent HTTP request may consume the response before a later call.

Weighted and Rule modes return `applicable: false`, `reason: "not_applicable"` and null response/cursor/position, never an estimated selection. Finished runs may still be inspected, though their routing remains closed. A dedicated regression test compares serialized raw call-state rows before/after repeated peeks, observes SQL to prohibit writes to that table and checks no lazy row is created. The official PHP MCP client exercises sequence creation, peeking, serving and rule updates over both HTTP and verified HTTPS.

### Verified prerequisite CI

## Tested local client and complete workflow

The tested client is **official `laravel/mcp` 1.0.2 PHP WebClient + explicit bearer token**, using Streamable HTTP protocol `2025-11-25`. The integration suite also checks `2026-07-28` stateless discovery. OAuth, stdio and specific editor/cloud-agent integrations were not tested and are not claimed. Credentials are provisioned locally; no external auth service is needed.

After `make setup`, `make up`, `make health`, issue a credential as shown above. The runnable example is [scripts/mcp_example.php](../scripts/mcp_example.php), exercised by the client integration test. With host PHP/dependencies available:

```bash
export MOCKDECK_MCP_URL=http://localhost:18473/mcp
# Supply the bearer through your shell/secret manager without committing it.
read -rsp 'MCP token: ' MOCKDECK_MCP_TOKEN; export MOCKDECK_MCP_TOKEN; echo
php scripts/mcp_example.php
unset MOCKDECK_MCP_TOKEN
```

Its client configuration is:

```php
$client = Laravel\Mcp\Client::web(getenv('MOCKDECK_MCP_URL'))
    ->withToken(getenv('MOCKDECK_MCP_TOKEN'))
    ->withProtocolVersion(Laravel\Mcp\Enums\ProtocolVersion::V2025_11_25);
$tools = $client->tools();
$result = $client->callTool('start_test_run', ['label' => 'local test']);
$runId = $result->structuredContent['data']['run_id'];
```

The example starts a run, atomically creates GET `/demo`, calls it once with the required run header and once using the run path prefix, reads an exact count of **2**, asserts equals 2 and finishes with `delete_mocks` in a finally block. It prints only tool count/run ID/mock ID/count/assertion status. The generated invocation forms are:

```bash
curl http://localhost:18473/demo -H 'X-MockDeck-Test-Run: RUN_UUID'
curl http://localhost:18473/_mockdeck/run/RUN_UUID/demo
```

For raw protocol inspection:

```bash
curl "$MOCKDECK_MCP_URL" \
  -H "Authorization: Bearer $MOCKDECK_MCP_TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"local-check","version":"1"}}}'
```

Native clients send the bearer on **every** HTTP request. HTTPS uses a trusted certificate as described in [HTTPS.md](HTTPS.md); install/trust the CA in the client’s OS/container too. Do not disable certificate verification. APP_URL controls generated URLs; it does not make an unreachable address reachable.

## Host and container addresses

| Client/application location | Reachable MCP URL / mock origin |
|---|---|
| Host machine | `http://localhost:18473/mcp`, mock origin `http://localhost:18473` |
| Same Compose network | `http://nginx:80/mcp`, mock origin `http://nginx:80`; published host port is irrelevant inside this network |
| Another container outside that network | Join the network or use a reachable host gateway; `localhost` means that container itself |
| HTTPS through shared-port Nginx | `https://<certificate hostname>:18473/mcp` from host; on the Compose network use a hostname/address covered by the trusted certificate on Nginx port 80 |
| Remote/cloud agent | A deliberately reachable deployment with trusted TLS and explicit bearer; host loopback is inaccessible remotely |

For the runnable example inside Compose, pass exported environment variables rather than embedding a bearer in a command:

```bash
export MOCKDECK_MCP_URL=http://nginx:80/mcp
export MOCKDECK_MOCK_ORIGIN=http://nginx:80
docker compose exec -e MOCKDECK_MCP_URL -e MOCKDECK_MOCK_ORIGIN -e MOCKDECK_MCP_TOKEN app php scripts/mcp_example.php
```

Returned URLs use APP_URL. Replace only the origin when necessary, preserving the request path/query and run routing metadata. In HTTPS container traffic, use a certificate SAN matching the actual reachable hostname. A locally hosted MCP server has no cloud runtime dependency; a cloud LLM/agent may still rely on its own provider/network, independently of MockDeck.

## Troubleshooting

| Symptom | Check |
|---|---|
| HTTP 401 / AUTH_ERROR | Use an unrevoked `mdm_` bearer on each request. A dashboard cookie or `mdv_` verification token is insufficient. Reissue a lost token; it cannot be retrieved. |
| FORBIDDEN | Grant the tool’s explicit permission; read/write/verify/cleanup do not imply each other. Check trusted Origin for browser callers. |
| HTTP 429 | Defaults are 30 requests/minute/IP and 60/minute/credential; clients sharing NAT also share the IP limit. Respect retry timing or configure limits deliberately. |
| Ordinary “No mock configured” | Attach the run header or use the run prefix; ordinary requests never search run mocks. Check the method/path/query/body and matcher exclusions. |
| Run request unmatched | Use get_unmatched_requests then explain_match with the retained ID, or provide curl text. No fallback to ordinary mocks exists. Credential revocation also disables its active runs. |
| Old unmatched ID unavailable | Evidence is capped at 20 entries/24 hours, separate from dashboard log retention. Supply full curl when expired/truncated. |
| INCONCLUSIVE_EVIDENCE | Reset before the test and assert promptly within 20 calls; use unfiltered exact counts when suitable. Sensitive/oversized retained values can also make some predicates undecidable. |
| CONFLICT on update | Read get_mock, reconcile and retry with its current version. Never silently force an overwrite. |
| CONFLICT on create | A matching canonical signature already exists in this run. Inspect the named mock and update it; other runs can use identical shapes. |
| IDEMPOTENCY_CONFLICT | The key was reused with different full input. Retry identical input or choose a new key for a new operation. |
| RUN_FINISHED | Start a new run. Finishing again is safe and returns the first summary; it does not change cleanup choice. |
| TLS failure | Trust the local CA in the client’s actual environment and match the certificate hostname. Follow HTTPS.md; never solve this with an insecure verification bypass. |
| Container connection refused / Nginx 502 | Use the reachable service origin; run make health and make nginx-check, inspect app/nginx logs and the existing DNS-recovery guide. This is upstream connectivity, not a signature mismatch. |

Maintain `mockdeck:mcp-prune` on an hourly schedule for physical TTL cleanup. Audit and run-history retention are deliberate operational records and are not pruned by that command. Finished runs remain available to list/read/assert; routing and new mutations are closed. P0 reads deliberately exclude ordinary dashboard mocks even when the credential has read permission.

Candidate explanations always include the real selected endpoint, then rank the bounded window by method/path agreement and configured priority. The winner itself is selected solely by the existing matcher, including its header-policy specificity. History and cached-create outputs use the same secret redaction as live results.

Template/pool coverage also creates a V6 path-pattern mock through the structured MCP input, renders path captures plus the bound Environment and seeded UUID token, then replaces a response through add/remove while preserving omitted matching fields and the original curl. No-op patches preserve the configuration version.

Atomic rollback is exercised with a second response containing a CRLF header value: it passes the public structural schema but fails the shared response validator after the endpoint and first response have been built. The outer transaction leaves neither partial configuration nor a retry cache entry.

## Postman import preview

`preview_postman_import` requires **read**, an owned `run_id`, and raw JSON text in `collection`; optional `environment` is companion Postman Environment JSON text. Both files use `MOCK_CONFIG_MAX_BYTES` (2 MiB each by default), counted in UTF-8 bytes, plus the existing 500-endpoint/5,000-response mapping caps. JSON-RPC escaping has a separate bounded envelope allowance (12 × file cap + 64 KiB); other tools retain the 1 MiB transport bound. The shared Nginx body ceiling is 25 MiB for the default two-file worst case; deployments increasing the file cap must raise that ceiling too. Application upload caps remain unchanged.

Optional `mode` is `create-only` (default), `upsert` or `clone`; correlation examines only endpoints in this run. `variable_mode` is `exclude` (default) or `resolve`, using the **run-bound** MockDeck Environment. Resolve freezes literal matching values and reports missing keys as blocking plan errors. Optional `mask_secrets` uses the dashboard mapper's existing masking switch (default false). Companion values are candidates only; these tools never auto-create or overwrite Environment variables.

The result is the dashboard `ImportPlan.toArray()` shape (`metadata`, `counts`, `items`, `errors`, `warnings`, `can_apply`, `mode`, `replace_responses`, `token`, `digest`), passed through normal MCP redaction. `token`/`digest` are unused placeholders: no preview cache is created. Per-request actions and warnings, flattened Collection names, variable candidates/provenance and the boolean `contains_secrets` banner flag are retained. Invalid file structures produce `VALIDATION_ERROR`; mapped per-request blocking issues remain in `errors` with `can_apply: false`.

The landed mapper supports embedded/folder variables, companion precedence, exclusion/resolve, and saved-response environment/dynamic-variable templates. Scripts have differentiated Pre-request/Test warnings and line counts only. Script source display and static `pm.globals/environment.set()` name extraction have **not** landed; this tool does not claim those capabilities. No script text enters the mapped configuration or any execution facility.

Like `preview_template`, Postman preview skips tool audit and credential last-used writes as well as configuration/runtime writes, including invalid input. Infrastructure rate limiting and server diagnostics remain enabled. HTTP tests compare every table and observe zero mutation SQL with the array cache backend; the existing full PHPUnit CI jobs discover these tests automatically.

## Apply a Postman import to a test run

`apply_postman_import` requires **write** (not read or cleanup), an active owned `run_id`, the same raw `collection`/optional `environment` text and options as preview, and `idempotency_key` (up to 128 characters). `mode`, `variable_mode` and `mask_secrets` have exactly the preview defaults. No source files are read on the server: the client sends their content.

Raw content is intentionally re-supplied rather than referring to cached preview tokens. Preview stays nonpersistent, has no expiry, and has no server-side secret-bearing upload cache. The existing `McpIdempotency.execute()` hashes the **full input** (including companion content and options) and caches only the redacted result under credential/run/tool/key for 24 hours. Identical retries return the original result; changed input under that key produces `IDEMPOTENCY_CONFLICT`. As with `create_mock`, a cached successful result can be replayed after the run finishes; new imports into finished runs return `RUN_FINISHED`. An expired key is a new operation, so default Create only remains useful for safe re-imports.

`MappedImportService.applyDocument()` is the single shared dashboard/MCP writer. It re-analyzes actions at apply time under the existing MCP run lock/transaction, so changes since preview are reflected in the returned `plan`. Run-owned endpoints are made through `TestRun.endpoints()` (the foreign key exists **before the first INSERT**), never created as ordinary mocks and moved later. Responses inherit ownership through their endpoint. Correlation queries and updates stay within that run; an ordinary or another run's identical key does not cause a skip/update. Existing import duplicate-signature semantics remain unchanged.

Folder names still flatten to shared MockDeck Collections (`Auth / Login`); Collection names/metadata are not private run data. Importing can create these shared folders just as dashboard import does. Their creation rolls back with a failed import, but finishing/deleting a run does not delete shared Collections. Ordinary endpoints/responses are untouched, and run endpoints/responses/history remain excluded from native endpoint exports. Environment variables are never created or overwritten. For `resolve`, both preview and apply independently read the current run-bound Environment values; re-preview if those values change before applying.

Output contains `run_id`, `summary` (the existing `ImportSummary` counters and `import_batch_id`), the applied dashboard-shaped `plan`, and `items` for changed mocks only. Each changed item has `mock_id`, `name`, `action`, `response_count`, `revision_reference`, and `invocation` with header/prefix URLs and POSIX curls. Skips remain in `plan.items` and have no changed item. Redaction is identical to other MCP tools: do not send redacted curls back as edits. Imported requests with literal secret headers still require those actual header values when called. Imports can exceed `get_mock`'s 50-response readback window; `response_count` reports the full pool.

Every changed run mock records an initial configuration or update pre-state with the existing revision manager and one run-scoped `import_batch_id`. Dashboard imports retain their original revision behavior. Apply writes a normal invocation audit on success/failure; source JSON, companion values and script text are never audit payloads. Import validation or an insert failure rolls back endpoints, responses, folders, revisions and idempotency cache insertion atomically; the error audit remains. Scripts are only detected by the mapper: no evaluation, JavaScript runtime or translation is added.

### Worked preview → apply → routed calls

Using the tested official Laravel PHP MCP client with its own bearer, prepare a v2.1 collection with a GET `/imported-client` request and a saved 200 example body `{"imported":true}`. The HTTP/trusted-HTTPS integration test exercises these exact steps:

```php
$run = $client->callTool('start_test_run', ['label' => 'Imported fixtures'])->structuredContent['data']['run_id'];
$args = ['run_id' => $run, 'collection' => file_get_contents('fixtures.postman_collection.json')];
$preview = $client->callTool('preview_postman_import', $args)->structuredContent;
// Review $preview['data']['items'], variable_candidates, warnings and can_apply.
$applied = $client->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'fixtures-v1'])->structuredContent;
$mock = $applied['data']['items'][0];
// $mock['invocation'] has callable URLs, routing headers, and a curl for each form.
```

For the returned run ID `RUN` and default host deployment:

```bash
curl -H 'X-MockDeck-Test-Run: RUN' http://localhost:18473/imported-client
curl http://localhost:18473/_mockdeck/run/RUN/imported-client
```

Both return the saved example. `get_calls` for that `run_id` / `mock_id` reports `total_match_count: 2`. Repeating apply with `fixtures-v1` returns the original summary/IDs without creating anything; changing `mode` to `clone` under the same key returns `IDEMPOTENCY_CONFLICT`. Supply a new key for an intentional clone/update. Use the normal `finish_test_run` cleanup afterward.

Tests cover dashboard parity, zero preview database writes/table changes, both routing forms, owner/run isolation, all import modes, first-insert ownership, failure rollback, template rendering against the run Environment, retry conflict/finished-run semantics and script non-execution/source non-retention. Source checks prohibit process/evaluation/runtime APIs in the mapper/orchestrator path. The official client covers preview/apply/retry/conflict and routed call counts over HTTP and verified HTTPS, under the same full PHPUnit and network-isolated CI workflow already used for Phase 3.
