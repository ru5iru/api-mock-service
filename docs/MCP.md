# MockDeck MCP foundations

Phase 1 prepares shared mutation services and independent credentials. It adds no MCP endpoint, transport, tool handlers or test-run routing. The dashboard and mock-serving behavior stay unchanged. Later phases extend this document.

## Behavior baseline

`MutationCharacterizationTest` freezes endpoint create/update/delete behavior, duplicate/sample messages, credential warnings and masking, response header/seed/callback validation, callback secret retention, toast messages, and revision pre-states before extraction. Existing draft, selection, callback, matching, template, import and verification suites remain mandatory regression coverage. CI runs all `tests/Feature` and `tests/Unit` through `php artisan test` and again in the container `make validate` job; no separate opt-in test command is needed.

## Existing versioning boundary

History uses per-entity `revisions.version_number` (max + 1) and pre-change snapshots; model timestamps also exist. The current editor has no general optimistic-concurrency guard. Future update tools must reuse history and coordinate endpoint/response changes under the existing transaction/locking conventions; a history number must not be represented as an already-enforced concurrency token.

## Scope

No MCP dependencies or network services are installed in Phase 1. Existing verification bearer tokens remain scoped to `/api/v1/verify/*`. MCP credentials will be separate, with no automatic upgrade of those tokens. No authentication middleware will be applied to ordinary serving or dashboard routes.

## Shared service contract

All classes below are in `App\Services\Mutations`; none accepts a Livewire component or HTTP request. `EndpointInput`, `ResponseInput` and `SelectionInput` are immutable, explicitly typed scalar/array DTOs. They describe complete form configurations, not PATCH payloads. A future PATCH tool must merge omitted fields from stored configuration before constructing a DTO.

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

Creation continues to allow response-less endpoints as the dashboard does today. Future atomic `create_mock` must compose endpoint/response service calls in an outer transaction; this phase does not change that existing dashboard behavior. Domain services do not enforce future credential/run ownership: the authenticated tool boundary must do so. Typed inputs are internal application contracts, not a promise to accept untrusted client-supplied secret-presence flags.

`SharedMutationServiceTest` exercises these services directly without Livewire, covering non-writing validation/preview, canonical bytes, secret analysis, scoped response updates, revision pre-states, sequence renumbering and rollback on invalid pools.

## Independent credential and permission model

`mcp_credentials` is deliberately separate from `api_tokens`. The latter has a singleton verification CLI and a fixed verification-only bearer format; extending it would risk accidentally turning verification credentials into management credentials. No migration, middleware or command changes touch that table. Existing `mdv_` tokens keep the same verification routes/behavior and are rejected by MCP authentication; new `mdm_` credentials cannot call the verification HTTP API. A future MCP verify tool uses its internal verification service under the MCP credential, not that existing HTTP bearer gate.

Each credential stores `name`, unique SHA-256 `token_hash`, `can_read`, `can_write`, `can_verify`, `can_cleanup` booleans (all default false), `last_used_at`, `revoked_at`, and `created_at`. Plaintext is returned in a non-serializable `IssuedMcpCredential` once and is never stored in the database or model serialization. Generate at least one explicit permission. Names identify credentials for operators; use the immutable numeric ID for revocation, including when names repeat.

`McpCredentialService` generates/authenticates/authorizes/revokes. Every authorization re-reads database state; revoked credentials and already-loaded stale models fail immediately on their next check, without cache/grace period. Revocation cannot cancel an already-completed operation. `AuthenticateMcpCredential` only reads bearer credentials, never dashboard sessions. It is prepared but not attached to production routes in Phase 1. Future handlers must authorize again at their operation boundary rather than treating earlier discovery as permanent authorization.

### Future P0 tool permissions

| Category / tool | Required permission |
|---|---|
| `start_test_run`, `create_mock`, `update_mock` | `write` |
| `list_mocks`, `get_mock`, `validate_mock`, `get_mock_url`, `explain_match` | `read` |
| `get_unmatched_requests`, `get_calls`, `assert_calls` | `verify` |
| `reset_test_run` | `cleanup` |
| `finish_test_run`, including its delete option | `cleanup` |

Permissions do not imply each other. To complete the full create/inspect/assert/cleanup workflow, grant all four. Finishing closes a run even without deletion, so it also requires `cleanup`. No extra `write` permission is required for runtime reset/cleanup; ownership checks in later phases remain mandatory for every tool. Discovery/initialization requires a valid credential; future discovery may filter tools by permissions. `McpToolPermissions::REQUIRED` is a tested, fail-closed contract; unknown tools do not get a default permission. There are no callable tools yet.

### Rate-limiting convention

Existing routes already use Laravel `throttle` middleware. Future routes must apply, in order, `throttle:mcp-auth`, `AuthenticateMcpCredential`, then `throttle:mcp`, before parameter binding/tool execution. The first limiter bounds authentication attempts at 30 requests/minute/IP; the second bounds authenticated requests at 60/minute/credential. Limits are configured in `config/mcp.php`. Every request passes the IP guard, so agents behind the same proxy/NAT also share that guard. Configure trusted proxy handling correctly before external exposure. These named limiters are registered now without altering any existing route.

`McpCredentialTest` checks one-time reveal/hash-only storage, stale-model revocation, 401 versus 403, session independence, verification/MCP token separation, unchanged verification schema, the complete permission map and named limiter keys/defaults.

## Credential lifecycle (CLI)

Apply migrations through normal `make up` deployment before issuing credentials. No MCP route is available yet, so generated credentials cannot connect an agent until transport/tool phases land.

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

`mcp_invocations` contains `id`, `credential_id` (FK to `mcp_credentials`, deletion restricted), nullable `tool_name`, nullable `target`, nullable UUID `run_id` (no run table/FK yet), `result` (`success`/`error`), nullable `error_code`, UUID `correlation_id`, and `created_at`. Revocation preserves the actor row. The model/schema do not automatically record calls: Phase 3 tool handlers will write invocation rows. No request payload column exists. Unauthenticated requests without an identifiable credential will need separate redacted operational diagnostics rather than a fabricated audit actor.

`McpAuditRedactor` reuses `RequestCredentialPolicy`'s configurable header/query detection and masking plus `EnvironmentVariableWriter::sensitiveName`. It recursively redacts named secrets, header/variable entry values, JSON-encoded metadata, credential-shaped bearer strings and sensitive query parameters; depth/string output is bounded. The `McpInvocation` target setter also applies redaction. Do not put raw request/response bodies or arbitrary free-form payloads into `target`: use compact identifiers. No heuristic can identify every unlabeled secret. Future writers must apply the helper before persistence, allowlist audit metadata, and must not bypass the model with unredacted query-builder inserts.

Credentials and invocation history are operational/security state, excluded from native configuration export/import and revision snapshots. No export-format bump or serving-state migration is required. `McpAuditContractTest` covers schema, no automatic writes, nested/custom secret detection, metadata bounds, enum codes and export exclusion.

## Fixed error-code taxonomy

`App\Services\Mcp\McpErrorCode` is the stable code vocabulary. It is distinct from MCP protocol/transport error codes; later handlers expose it in structured application results.

| Code | Meaning |
|---|---|
| `VALIDATION_ERROR` | Input/configuration failed domain validation; field-level detail may be supplied after redaction. |
| `AUTH_ERROR` | Missing, malformed, unknown or revoked bearer credential. Prepared middleware returns HTTP 401. |
| `FORBIDDEN` | Authenticated credential lacks the required permission; also used for disallowed scope in later phases. Prepared middleware returns HTTP 403. |
| `CONFLICT` | Configuration changed since the caller's expected revision/version or another configuration conflict prevents the operation. |
| `RUN_NOT_FOUND` | Referenced test run is not available to this caller. Run handling is not implemented yet. |
| `RUN_FINISHED` | Requested operation requires an active run but the run is already finished. |
| `ASSERTION_FAILED` | Complete available evidence demonstrates the expectation was not met. |
| `INCONCLUSIVE_EVIDENCE` | Evidence is incomplete or cannot evaluate the requested predicate; do not report a false exact count. |
| `IDEMPOTENCY_CONFLICT` | An idempotency key was already used with a different operation/payload. |
| `NOT_FOUND` | An accessible endpoint/response or other target does not exist. |

Only authentication/permission middleware uses these codes in Phase 1, and that middleware has no production route yet. Existing dashboard validation messages and verification API responses are unchanged. Run, assertion and idempotency tool outcomes belong to subsequent phases.

## Compatibility verification

The one-time issuance wrapper also rejects cloning, serialization and debug disclosure of its bearer. Migration rollback/reapply tests compare existing endpoints, responses, verification tokens and runtime counters byte-for-byte. Eleven standard/custom error cases were additionally compared against the pre-extraction components and matched exactly; they are retained in characterization coverage. The full existing application suite remains the broader behavior baseline. These checks prepare infrastructure only: no agent connection is available in Phase 1.
