# Verification API

Use the verification API to check how many requests matched an endpoint, inspect bounded request evidence, and reset runtime counters between CI tests. It is independent of request-log retention. Endpoint identifiers are portable **UUIDs**, available in native configuration exports; database row IDs are not accepted by these routes.

## Authentication

After applying migrations, generate the instance token:

```sh
php artisan mockdeck:api-token
```

The command prints a random `mdv_…` bearer token once. Save it as a CI secret. The database stores only its SHA-256 hash and last-use timestamp; the token is never exported or placed in revision snapshots. Repeating the command fails without showing or replacing the existing secret. To replace a lost or compromised token:

```sh
php artisan mockdeck:api-token --rotate
```

Rotation immediately revokes the previous token. There is one instance verification token and no scope-management UI. It grants access to the read, assert, and runtime-reset operations below. Use HTTPS when accessing the instance remotely.

Every request requires `Authorization: Bearer <token>`. Missing, malformed, or revoked tokens receive HTTP **401**, including when the operator is logged into the dashboard or dashboard authentication is disabled. Session cookies do not grant API access. Authentication runs before endpoint UUID lookup. Successful responses are not cached. These routes accept JSON and do not require a browser session or CSRF token.

Example shell setup (use your CI secret for `MOCKDECK_TOKEN`):

```sh
export MOCKDECK_URL='http://localhost:8080'
export ENDPOINT_UUID='replace-with-the-endpoint-uuid'
export ENVIRONMENT='Development'
# Set MOCKDECK_TOKEN through your CI secret store, not in committed scripts.
```

## Scope and counting

`environment` is required and is the environment's exact stored name, not its database ID. An unknown endpoint UUID or environment returns **404**; malformed input returns JSON validation errors with **422**. Selecting an environment here reads or resets that environment's counters; it does not activate the environment for mock serving. Mock traffic continues to use the instance's active environment.

A call is counted once when an endpoint matches, before the selected response is rendered or a fault is applied. Faulted responses, exhausted sequences, and matched endpoints with no response still count. Unmatched requests do not count. Weighted, sequence, and rule modes share the same counters. Counters and retained evidence update together under the existing transactional row lock.

State is created lazily by a match. Reading, asserting, or resetting an untouched endpoint returns zeros and does not create a state row. `total_match_count` is an exact count since the most recent verification reset (or since first tracking if never reset). The dashboard's **Reset sequence** action resets only sequence position; it does not reset verification counts or evidence.

## Read calls

`GET /api/v1/verify/endpoints/{endpoint_uuid}/calls?environment=<name>`

```sh
curl --fail-with-body --get \
  "$MOCKDECK_URL/api/v1/verify/endpoints/$ENDPOINT_UUID/calls" \
  --header "Authorization: Bearer $MOCKDECK_TOKEN" \
  --data-urlencode "environment=$ENVIRONMENT"
```

Example response:

```json
{
  "endpoint": "replace-with-the-endpoint-uuid",
  "environment": "Development",
  "total_match_count": 2,
  "last_matched_at": "2026-10-03T12:00:00+00:00",
  "sequence_position": 2,
  "recent_calls": [
    {
      "matched_at": "2026-10-03T11:59:59+00:00",
      "method": "POST",
      "path": "/orders",
      "header_digest": {
        "sha256": "…",
        "complete": true,
        "fields": {"x-tenant": {"scalar": true, "sha256": "…", "value": "blue"}}
      },
      "query_digest": {"sha256": "…", "complete": true, "fields": {}},
      "body_digest_or_snippet": {
        "sha256": "…",
        "bytes": 13,
        "complete": true,
        "fields": {"amount": {"scalar": true, "sha256": "…", "value": "10"}}
      }
    }
  ],
  "window": {"capacity": 20, "retained_calls": 1, "unretained_calls": 1}
}
```

`sequence_position` appears only for sequence endpoints. It is the zero-based position to be considered by the next selection, before loop/exhaust behavior is applied. `last_matched_at` is `null` before any call or after verification reset. Calls are returned oldest first within the retained window. The example deliberately has an unretained call, such as a counter recorded before verification evidence was introduced.

### Bounded evidence

Each endpoint/environment retains at most **20** recent call digests. Old entries are evicted without reducing the lifetime counter. Each entry stores method (32 bytes maximum), path (2,048 bytes maximum), timestamps, and projections of headers, query fields, and JSON-body paths. These are verification records, independent of general Logs; clearing or rotating Logs does not remove them.

Each projection retains at most **64** fields, names/paths at most 255 bytes, and plaintext scalar values at most **256** bytes. JSON nesting is limited to 16 levels, and a body larger than **64 KiB** retains its hash and byte count but no JSON-field projection. No complete raw body is stored. Hashes always use SHA-256; scalar hashes cover the same string representation the rule evaluator compares (`true`/`false` for booleans). Null/object/array values retain existence metadata but cannot match scalar operators.

Sensitive names containing authorization, cookie, password/passwd, secret, token, API key, or credential retain only scalar hashes, including nested paths under those names. Oversized or invalid-UTF-8 scalar values also retain hashes only. These fields carry an `omitted` reason. A projection's `complete: false` means additional fields may exist beyond what was retained. Do not treat a missing projected field as absent when this flag is false.

## Assert calls

`POST /api/v1/verify/endpoints/{endpoint_uuid}/assert`

```sh
curl --fail-with-body \
  "$MOCKDECK_URL/api/v1/verify/endpoints/$ENDPOINT_UUID/assert" \
  --header "Authorization: Bearer $MOCKDECK_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"environment":"Development","expect_count":2,"comparator":"equals","matching":[{"field_type":"header","field_name":"X-Tenant","operator":"equals","value":"blue"}]}'
```

Request fields:

| Field | Meaning |
| --- | --- |
| `environment` | Required exact environment name. |
| `expect_count` | Required integer from 0 through 2,147,483,647. |
| `comparator` | `equals`, `at_least`, or `at_most`. |
| `matching` | Optional array of at most 32 conditions, combined with AND. Omit or use `[]` for exact unfiltered lifetime counts. |

Conditions use the **same evaluator and grammar as response rules**:

| Field | Values |
| --- | --- |
| `field_type` | `header`, `query`, `body_json_path`. |
| `field_name` | Header name (case-insensitive), exact query parameter name, or Laravel dot JSON path; maximum 255 characters. |
| `operator` | `equals`, `contains`, `regex`, `exists`. |
| `value` | String up to 4,096 characters; required except for `exists`, where it is ignored. |

`equals` and `contains` are case-sensitive comparisons of scalar string values. JSON booleans compare as `true` or `false`; a null-valued field matches `exists` but not scalar operators. Query arrays similarly match existence only. Regex values must include delimiters, for example `/^order-[0-9]+$/i`; invalid regex is a validation error. All conditions must match the same call. Header lookup compares the first header value, as response rules do. These conditions do not alter endpoint matching policy, exclusions, or response selection.

A proven pass returns **200**:

```json
{
  "passed": true,
  "actual_count": 2,
  "message": "Endpoint …, environment Development: Expected equals 2 matching calls since reset; observed 2.",
  "verdict": "passed",
  "scope": "since_reset",
  "count_bounds": {"minimum": 2, "maximum": 2},
  "window": {"total_match_count": 3, "retained_calls": 3, "unretained_calls": 0, "undecidable_retained_calls": 0, "capacity": 20}
}
```

A proven failure returns **422**, `passed: false`, and `verdict: "failed"`. The message includes the endpoint, environment, comparison, expected count, and observed count/bounds for CI output.

### Assertions beyond retained evidence

Filtered assertions apply to the count **since reset**, not merely to a conveniently available sample. If calls have been evicted, predate this feature, or depend on omitted values, the API computes conservative lower/upper bounds. It returns `actual_count: null` when the exact count cannot be recovered. A condition can still be proven: for example, 20 known matches prove `at_least: 20` even if an older call is unavailable.

When neither pass nor failure can be proven, the response is **422**, `passed: false`, `verdict: "indeterminate"`, with bounds and an explanatory message. It never reports a partial-window exact count as the lifetime count. Exact-value predicates can compare hashes for sensitive/oversized scalar values; `contains`/`regex` need retained plaintext and otherwise become indeterminate. A known false condition still makes the call a nonmatch even if another AND condition is unknown.

For exact filtered CI assertions, reset immediately before the scenario, keep it within 20 matched calls, and use fields within the documented projection bounds. Unfiltered count assertions stay exact without that window limit.

## Reset one endpoint

`POST /api/v1/verify/endpoints/{endpoint_uuid}/reset`

```sh
curl --fail-with-body \
  "$MOCKDECK_URL/api/v1/verify/endpoints/$ENDPOINT_UUID/reset" \
  --header "Authorization: Bearer $MOCKDECK_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"environment":"Development"}'
```

Example: `{"reset":true,"environment":"Development","endpoint":"…","state_rows_reset":1}`.

This atomically resets `total_match_count` and `sequence_position` to zero, clears `recent_call_digests`, and sets `last_matched_at` to null for that endpoint/environment only. An untouched scope returns `state_rows_reset: 0`. Other environments and endpoint configuration remain unchanged.

## Reset all endpoints in one environment

`POST /api/v1/verify/reset-all?environment=<name>`

```sh
curl --fail-with-body --request POST \
  "$MOCKDECK_URL/api/v1/verify/reset-all?environment=Development" \
  --header "Authorization: Bearer $MOCKDECK_TOKEN"
```

Example: `{"reset":true,"environment":"Development","state_rows_reset":5}`.

This performs one atomic update over existing state rows in the selected environment. It does not create rows for untouched endpoints and cannot reset another environment. URL-encode environment names containing spaces. As with single-endpoint reset, it never changes responses, rules, exclusions, matching policy, or revisions. Runtime state and token hashes are excluded from configuration export/import and revision restore.

For a deterministic test boundary, stop previous test traffic, reset, run the scenario, and assert. Concurrent arrivals serialize with state updates; a call after the reset's database update belongs to the new run. Share an environment across simultaneous CI jobs only if they intentionally share counters.
