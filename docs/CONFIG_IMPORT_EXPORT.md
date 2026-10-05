# Native configuration import and export

MockDeck's native JSON format moves endpoint definitions, responses, collections, tags, and environments between installations without coupling files to database IDs or trusted hashes. The current media type is `application/vnd.mockdeck.config+json`; the current format version is `1.5`, with version `1`, `1.0`, `1.1`, `1.2`, `1.3`, and `1.4` imports retained for backward compatibility.

## Safe dashboard workflow

Open `/dashboard/config` after signing in.

For export:

1. Select individual endpoints or use **Export all JSON**.
2. Keep **Redact request secrets** enabled for normal sharing and source control.
3. If an unredacted file is genuinely required, disable redaction and acknowledge the credential warning.
4. Store unredacted files with the same protections as secrets.

For import:

1. Choose a mode and `.json` file.
2. Select **Preview import**. This is a dry run and performs no model writes.
3. Review counts, every endpoint action, validation errors, exact conflicts, overlap warnings, and redaction warnings.
4. Resolve errors. Explicitly acknowledge warnings when present.
5. Select **Apply import**. The service revalidates and rechecks conflicts in one transaction before committing.

For Update by UUID, preview shows how many existing endpoints/responses will receive a pre-state revision. A successful update exposes **Undo this import** permanently for that batch. The confirmation lists every recorded entity; undo restores them together and appends rollback revisions rather than rewriting history.

Preview tokens expire after 15 minutes by default and are bound to the SHA-256 digest of the uploaded bytes. A changed or expired plan must be previewed again.

## Import modes

| Mode | Existing endpoint UUID | Exact signature on another endpoint | No conflict |
|---|---|---|---|
| `create-only` | Error | Error | Create using imported UUID |
| `upsert` | Update by UUID | Error | Create using imported UUID |
| `clone` | Generate a new UUID | Error | Create with new endpoint/response UUIDs |

Upsert merges responses by UUID. Enable **Delete local responses omitted from updated endpoints** only when the imported response list should be authoritative. The default preserves unmentioned local responses.

An upsert uses one `import_batch_id` for every changed existing endpoint/response. No-op writes create no revisions. Responses removed by authoritative replacement are snapshotted before deletion so batch undo can recreate them. Create-only and clone imports do not have pre-existing entity states and therefore do not expose a history-based undo batch.

Exact origin-independent signatures are errors because they would be ambiguous. Broad/narrow signatures that can match the same live request are warnings. The preview shows the predicted winner using runtime priority, signature specificity, and stable ID ordering; importing requires acknowledgement.

## Secret handling

Default redaction removes:

- `Authorization` and `Proxy-Authorization`;
- `Cookie` and `Set-Cookie`;
- common API-key headers;
- configured sensitive query keys;
- URL credentials, which are already rejected by curl validation.

Configure comma-separated additions or replacements with:

```dotenv
MOCK_EXPORT_SENSITIVE_HEADERS=authorization,proxy-authorization,cookie,set-cookie,x-api-key,api-key,x-auth-token
MOCK_EXPORT_SENSITIVE_QUERY_KEYS=access_token,api_key,apikey,auth_token,key,token
```

When redaction removes a value, the export sets `enabled: false` and `requires_secret_replacement: true`. Import enforces the disabled state. Replace the missing value in the endpoint editor with an environment-appropriate credential, review the signature policy, and enable the endpoint deliberately.

MockDeck never exports dashboard credentials, secret environment-variable values, database settings, request logs, numeric database IDs, canonical strings, or stored hashes. Non-secret environment variables are portable configuration and are included.

Callback URL, method, headers, JSON body, timing, and signature-header name are exported inside each response's optional `callback` object. With normal redaction, configured sensitive callback headers and query keys are replaced. The callback signing secret is **never** exported, even with `--include-sensitive`: `signing_secret` is null and `signing_secret_redacted` marks an existing secret. Any callback requiring a redacted signing secret or request credential is exported disabled with `requires_secret_replacement: true`. Import disables signing until you provide a new secret; review the callback and enable it deliberately. Callback attempt logs and resolved payloads are never exported.

## Document contract

The current checked-in contract is `resources/schemas/mockdeck-config-v1.5.schema.json`; earlier schemas remain available for older documents. A current document contains:

- `format: "mockdeck"` and `format_version: "1.5"`;
- collection definitions, case-insensitive tag names, and environment definitions;
- non-secret environment variable values plus redacted secret placeholders;
- endpoint collection, tags, and named environment overrides;
- export metadata and whether secrets were redacted;
- stable endpoint and response UUIDs;
- raw curl, source signature version, and matching exclusions;
- response status, headers, body, delay, and weight;
- additive template fields: `body_mode`, `template`, `editor_view`, `seed_mode`, `seed`, and `locale`.
- optional response `callback` configuration; signing secrets are always excluded.

Endpoint and response arrays are sorted by UUID. Response header keys are sorted case-insensitively. JSON uses four-space indentation, unescaped Unicode/slashes, and a final newline. Timestamps are metadata; repeated exports at the same fixed time are byte-identical.

Imported `normalized_curl`, `curl_hash`, and numeric IDs are unknown fields and are ignored with warnings. Every request is parsed and hashed again by the running application's canonicalization code. Unknown object properties warn; unsupported format versions and invalid required values fail.

Version 1, 1.0, and 1.1 imports remain supported. Missing organization fields produce no collection, tags, or overrides; missing template fields default to a static body. Template text is exported exactly as stored and is not redacted.

Environment secret values are never exported, even when request-secret redaction is disabled. Their entries use `value: null`, `is_secret: true`, and `redacted: true`. Import preserves an existing local secret when the incoming redacted value is null.

The dashboard can scope export to one collection or one environment. Environment scope includes endpoints with no override and endpoints with an enabled override; it excludes endpoints explicitly disabled in that environment.

## Limits

Defaults can be tightened through `.env`:

```dotenv
MOCK_CONFIG_MAX_BYTES=2097152
MOCK_CONFIG_MAX_ENDPOINTS=500
MOCK_CONFIG_MAX_RESPONSES=5000
MOCK_CONFIG_MAX_STRING_BYTES=1048576
MOCK_CONFIG_PREVIEW_TTL=900
```

JSON nesting is capped at 64. Request curls and response bodies use the configured string limit. Status, delay, weight, priority, UUIDs, matching flags, header names, and header values are validated before a plan can be applied.

## Artisan commands

```bash
# Redacted export of everything
php artisan mockdeck:export --output=mockdeck.json

# Redacted subset
php artisan mockdeck:export --endpoint=<uuid> --endpoint=<uuid> --output=subset.json

# Explicit sensitive export
php artisan mockdeck:export --include-sensitive --output=private-mockdeck.json

# Read-only preview
php artisan mockdeck:import mockdeck.json --dry-run
php artisan mockdeck:import mockdeck.json --dry-run --json

# Apply after reviewing warnings
php artisan mockdeck:import mockdeck.json --mode=upsert --acknowledge-warnings
php artisan mockdeck:import mockdeck.json --mode=upsert --replace-responses --acknowledge-warnings
```

Commands return a non-zero status for invalid modes, unreadable files, validation/conflict failures, expired previews, and unacknowledged warnings. `--json` emits one machine-readable object.

## Protected HTTP routes

All routes use dashboard session access, CSRF protection, and request throttling:

| Method and path | Purpose |
|---|---|
| `POST /dashboard/config/exports` | Download all, selected, collection-scoped, or environment-scoped endpoints |
| `POST /dashboard/config/imports/preview` | Upload `config`, choose `mode`, return a plan/token |
| `POST /dashboard/config/imports/apply` | Submit preview `token`, `digest`, and warning acknowledgement |
| `GET /api/imports/{batch_id}` | List the endpoint/response pre-states in one import batch |
| `POST /api/imports/{batch_id}/undo` | Restore every recorded pre-state and append rollback revisions |

Export accepts `endpoint_uuids[]`, `redact_secrets`, `confirm_sensitive_export`, and either `collection_id` or `environment_id`. Preview accepts multipart `config`, `mode`, and `replace_responses`. Apply accepts `token`, `digest`, and `acknowledge_warnings`.

## Recovery and validation

Imports are atomic: a late exception rolls back every endpoint and response write. If apply reports that conflicts changed, generate a new preview rather than retrying an old plan. Export a backup before a large upsert with response replacement.

Run:

```bash
make validate
```

For focused work:

```bash
make lint
make test-unit
make test-feature
```

The feature suite covers deterministic redaction, create-only round trips, UUID upsert idempotency, exact conflict blocking, overlap warnings, required acknowledgement, protected routes, HTTP preview/apply, CLI dry-run/apply, and portable UUID generation.

## Response selection in format 1.3

Endpoints include `selection_mode` (`weighted`, `sequence`, or `rule`). Weighted selection remains the default. `sequence_on_exhaust` is `repeat_last`, `loop`, or `not_found` in sequence mode and null in other modes.

Responses include nullable, zero-based `sequence_order`, boolean `is_default`, and `response_rules`. Each condition has `field_type` (`header`, `query`, `body_json_path`), `field_name`, `operator` (`equals`, `contains`, `regex`, `exists`), nullable `value`, and non-negative integer `priority`. Exists does not use a value; other operators require a string. Regex values use PHP pattern delimiters, for example `/^ready$/i`. Conditions on one response use AND. Rule-bearing responses are checked by their lowest condition priority; ties use response weight, then UUID. Exactly one response must be marked default in rule mode. Sequence orders must contain every position from zero through the response count minus one, without duplicates or gaps.

Version `1`, `1.0`, `1.1`, and `1.2` documents always import as weighted selection with no sequence order, default marker, or conditions, even if newer selection fields appear in the file. A version 1.3/1.4/1.5 upsert that merges unmentioned local responses must leave the combined selection configuration valid; preview blocks invalid merged defaults or sequence orders and suggests authoritative response-pool replacement.

`endpoint_call_state` is runtime data. Sequence positions, total match counts, last matched timestamps, and environment/database IDs are never exported or imported. New imports and clones have no call-state rows until first traffic. Updating an existing endpoint by UUID preserves its runtime state. Revision restore and import undo restore selection configuration and conditions while leaving runtime state unchanged.

Request-context tokens (`$request.method`, `$request.url`, `$request.body`, `$request.id`, `$request.json.<path>`) and `{{env.KEY}}` remain ordinary text in the existing `template` field. Export/import preserves that text exactly; their addition requires no extra portable fields or version bump on its own. Secret environment values remain excluded, so replace them locally before serving templates that need them.


## Wave 2 fields in format 1.4

Track C and Track D share one version bump from 1.3 to **1.4**. All previous format versions remain accepted.

`endpoint.request.matching` adds:

| Field | Type/default | Meaning |
|---|---|---|
| `excluded_query_params` | array of exact strings / `[]` | Omit each named parameter from canonical query matching. |
| `excluded_headers` | array of lowercase strings / `[]` | Omit named headers in addition to coarse policies. |
| `path_pattern_enabled` | boolean / `false` | Interpret complete `{name}` segments in `request.curl` as path parameters. |

Each exclusion list is limited to 100 names of at most 255 characters. Headers are normalized to lowercase. Parameter names must be unique identifiers. V6 is used only with nonempty exclusion lists or an enabled pattern. Its canonical path contains the pattern; query, retained headers, and body stay exact. Derived hashes are recomputed from imported cURL and policies. The imported source hash is never trusted.

Each response adds flat fields:

| Field | Type/default |
|---|---|
| `fault_enabled` | boolean / `false` |
| `fault_type` | `delay`, `malformed_body`, `truncated_body`, `timeout` / `delay` |
| `fault_delay_ms_min` | integer 0–120000 / `0` |
| `fault_delay_ms_max` | nullable integer, minimum ≤ maximum ≤ 120000 / `null` |
| `fault_probability` | integer 0–100 / `100` |

Malformed-body faults require a JSON/XML Content-Type (templates without an explicit type are JSON). `timeout` means a bounded long-delay approximation, not a guaranteed connection hang. `connection_reset` is unsupported and rejected.

Versions 1/1.0–1.3 always import with empty field exclusions, literal paths and disabled fault defaults, even if those newer keys are present. Existing 1.3 selection settings still round-trip; 1–1.2 retain the previous Weighted defaults. Revisions use the same compatible missing/null defaults. Upsert keeps destination runtime state; created or cloned endpoints have no state until a match.

The entire `endpoint_call_state` table, including `recent_call_digests`, remains runtime-only. It is never exported, imported, cloned, restored, or seeded by configuration transfer. API-token hashes are instance credentials and are also excluded. Verification's 20-call ring buffer is independent of request-log retention.

## External provenance in format 1.5

Wave 3 adds nullable endpoint `external_source`: an object containing `type`, `correlation_key`, `spec_title`, and `imported_at` strings. Nullable response `external_label` records an external saved-example name. These fields are portable and revisioned; they do not change matching or response selection. Files from 1–1.4 default both to null, even if they contain newer provenance fields. The existing Wave 2 fields remain unchanged.

The Import panel now offers Native / Postman with the shared segmented control. Native import/export behavior and warning acknowledgement stay unchanged. Postman Collection v2.1 import has correlation-based Create only / Update matching / Clone modes, flattened folders, saved examples, and informational per-request warnings. See [Postman import](POSTMAN_IMPORT.md). Postman export and OpenAPI are not included. Runtime state and verification digests remain excluded.
