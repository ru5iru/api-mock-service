# Native configuration import and export

MockDeck's native JSON format moves endpoint definitions, responses, collections, tags, and environments between installations without coupling files to database IDs or trusted hashes. The current media type is `application/vnd.mockdeck.config+json`; the current format version is `1.2`, with version `1`, `1.0`, and `1.1` imports retained for backward compatibility.

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

Preview tokens expire after 15 minutes by default and are bound to the SHA-256 digest of the uploaded bytes. A changed or expired plan must be previewed again.

## Import modes

| Mode | Existing endpoint UUID | Exact signature on another endpoint | No conflict |
|---|---|---|---|
| `create-only` | Error | Error | Create using imported UUID |
| `upsert` | Update by UUID | Error | Create using imported UUID |
| `clone` | Generate a new UUID | Error | Create with new endpoint/response UUIDs |

Upsert merges responses by UUID. Enable **Delete local responses omitted from updated endpoints** only when the imported response list should be authoritative. The default preserves unmentioned local responses.

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

## Document contract

The current checked-in contract is `resources/schemas/mockdeck-config-v1.2.schema.json`; earlier schemas remain available for older documents. A current document contains:

- `format: "mockdeck"` and `format_version: "1.2"`;
- collection definitions, case-insensitive tag names, and environment definitions;
- non-secret environment variable values plus redacted secret placeholders;
- endpoint collection, tags, and named environment overrides;
- export metadata and whether secrets were redacted;
- stable endpoint and response UUIDs;
- raw curl, source signature version, and matching exclusions;
- response status, headers, body, delay, and weight;
- additive template fields: `body_mode`, `template`, `editor_view`, `seed_mode`, `seed`, and `locale`.

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
