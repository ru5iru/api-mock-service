# Postman Collection import

MockDeck imports **Postman Collection v2.1 only**. Open **Import / export → Postman**, upload a collection JSON file, choose a mode, and select **Preview import**. Legacy/v1, v2.0, invalid JSON and malformed collection structure produce a blocking error. Mapping warnings are informational and do not disable **Confirm import**. Configuration import is atomic and requires confirmation; Environment variable creation is a separate explicit confirmation. Missing resolution values block import until repaired or excluded.

This wave does not export Postman collections or import/export OpenAPI. Companion Postman Environment files can supply reviewed variable candidates. Existing Environment variables are never silently created or overwritten.

## Folders and Collections

Nested folders flatten to full names joined with ` / `: `Auth` → `Login` becomes the MockDeck Collection **Auth / Login**. Root requests use `info.name`. These are flat MockDeck Collections, not nested collections. Empty folders create empty Collections, never fabricated endpoints. Identical flattened names share a Collection. Folder names containing ` / ` can therefore share a name with a nested path; rename them if separate Collections are needed.

The upload, endpoint and response caps are shared with native import: defaults are 2 MiB, 500 endpoints and 5,000 responses. Imports remain synchronous within these bounds, matching native import; callback delivery continues to use the existing queue. Folder traversal uses a stack rather than an application recursion limit; JSON decoding has a safety depth of 2,048. Flattened Collection names must fit the existing 255-byte database limit.

## URLs, variables and matching

Structured `url.path` and `url.query` are authoritative. Protocol, host and port are discarded: mock hosts are interchangeable. Raw-only URLs use the shared curl URL parser after removing variable authority fields. Disabled headers, query entries and form fields are skipped.

- A whole path segment `{{id}}` becomes `{id}` and enables Wave 2 path-pattern matching. Invalid/duplicate parameter names are made valid and unique with a warning. Captures use the existing `$request.path.id` response/callback context.
- Partial segments such as `v{{version}}` remain literal and generate a warning. When mixed with wildcard segments, literal braces are URL-encoded so they cannot be mistaken for `{name}` parameters. Use the existing path-segment editor to convert them manually.
- A header or query value containing an unresolved `{{variable}}` automatically adds its field name to `excluded_headers` (lowercase) or `excluded_query_params` (case-sensitive). Every exclusion is listed in that request's preview warnings. These endpoints use V6; fields remain available for operator review.
- Request-body variables stay literal. MockDeck has whole-body matching, not field-level body exclusion; the preview asks you to edit that body afterward.

Collection `variable[]` values are collected, and folder `variable[]` values scope to their descendants. A companion Environment file overrides same-name embedded candidates, with an explicit preview warning. Conflicting folder-scoped values are marked unknown rather than arbitrarily chosen for a global Environment. The shared mapped document exposes `variable_candidates` (name, value, known, is_secret, sources, scope_conflict) for subsequent import passes.

Original header/query tokens are retained in `external_source.variable_provenance` (field_type, field_name, tokens, mode), preserved by native 1.5 exports and revisions and annotated in Matching. This is optional metadata inside the existing source object, not a new format version or migration.

 Path wildcards combine with exact matching of the non-excluded query, headers and body; this does not turn the entire request into a wildcard.

## Authentication

Missing request auth inherits the nearest folder's auth, then collection auth. An explicit `noauth` stops inheritance. Generated auth replaces a same-name header case-insensitively.

| Auth type | Imported behavior |
|---|---|
| `bearer` | `Authorization: Bearer <token>`; variable token excludes Authorization |
| `basic` | `Authorization: Basic <base64(username:password)>`; variables are detected **before** encoding and exclude Authorization |
| `apikey` | Configured header or query key/value; variable values use the same exclusion rule |
| `noauth` | No generated credentials |
| `oauth2`, `digest`, `awsv4`, `ntlm`, `hawk`, any other type | Warning naming the type; no fabricated auth header |

The shared curl credential detector checks the complete request header/query set and saved-response headers, including Cookie, Authorization and Set-Cookie. **Mask detected secrets** rebuilds the preview with detected values replaced by `REPLACE_ME`, just as curl import does. Detection is name-based and configurable; it is not a body-secret scanner. Variable-based exclusions remain in place after masking. Literal credentials that are masked still match literally as `REPLACE_ME` until edited or excluded.

## Request bodies

| Postman mode | MockDeck body |
|---|---|
| `raw`, valid JSON | Pretty-stored JSON; existing canonical JSON matching |
| `raw`, invalid JSON (including trailing comments) | Original bytes retained; existing trimmed raw-text matching, with warning |
| `urlencoded` | Percent-encoded `key=value&key=value` text, disabled fields skipped |
| `formdata`, text parts | Same key/value text representation, with a warning that it is not multipart bytes |
| `formdata`, file parts | `[file content not included]` placeholder plus warning; exported file paths are never opened |
| `graphql` | JSON `{query, variables}`; invalid variables JSON stays literal with a warning |
| `none` or missing | Empty body |
| Other modes | Warning and empty body; no file reads |

Postman request headers are preserved. A valid raw JSON/GraphQL body with no Content-Type gets `Content-Type: application/json`, visibly warned in preview, so the existing canonicalizer uses JSON semantics. An explicitly declared Content-Type is kept (a non-JSON type retains raw-text matching). The mapper does not infer authentication flows, execute curl, fetch URLs or run JavaScript.

## Scripts and saved examples

Requests without their own events inherit the nearest ancestor event list. Non-empty pre-request and test scripts generate warnings naming the type and line count. Script content is never executed, translated, or retained in the mapped import cache/configuration. It exists only in the temporary uploaded source file until the normal upload cleanup.

Each saved example creates one Response: status from `code`, response headers, body, and the example name as `external_label`. All examples have weight 1 under **Weighted** selection. Invalid JSON response bodies stay raw with a warning. When `code` is missing/invalid, a valid HTTP/2 `:status` entry supplies the status with a preview warning; if neither is valid, status falls back to 200 with a warning. A valid `code` always wins. Pseudo-headers such as `:status`/`:authority` and invalid HTTP field names are skipped with preview warnings rather than stored as ordinary response headers. Duplicate response header names use the last value because MockDeck stores a header map.

Valid saved response headers remain available in configuration for review. At serving time, the web server owns Content-Length, CGI Status and hop-by-hop headers such as Transfer-Encoding/Connection; stale example framing is not replayed. Invalid field names and HTTP/2 pseudo-headers are also filtered at this boundary, covering older imports without re-importing. Postman-imported endpoints also omit the original Content-Encoding because saved example bodies are decoded text, not compressed wire bytes. Content-Type and application headers are preserved.

Use the dashboard's **Copy mock curl** to test the imported signature. It preserves literal body bytes and suppresses curl's implicit form Content-Type when no header was imported. Commented/invalid JSON remains raw text, including its comments. A request name is only a label: the request URL path defines matching. If multiple items have the same signature, normal priority/ID precedence selects one endpoint; rename alone does not distinguish them. Items with no saved examples need a response added before they can answer.

Copy resolves user-variable placeholders in headers/query values from the currently active MockDeck Environment, including URL-encoded placeholders. This happens on click, so later variable edits and Environment switches are reflected without re-importing. Missing keys produce a copy error naming the key. Exclude-mode fields stay excluded; Resolve-mode fields already imported as literal values remain those saved literals. Request bodies and literal path segments are not rewritten by Copy, preserving their exact matching behavior. The clipboard command includes any resolved secrets.

The original item request always defines matching. If an example's `originalRequest` headers/body differ, the preview warns that the example may be stale. Its URL does not override the item URL. Examples with no original request are accepted. Requests with zero examples create zero responses and show the existing no-response warning; no placeholder is fabricated. The imported example name is visible in the response row's **Details** disclosure.

## Modes, correlation and undo

| Mode | Existing correlation key | New key |
|---|---|---|
| Create only (default) | Skip, no write | Create |
| Update matching | Replace request and response pool | Create |
| Clone | Always create another endpoint | Create |

The correlation key is `folder_path + "/" + item_name + "|" + method + "|" + path`, with the flattened folder path and mapped path. Root requests have an empty folder prefix. Renaming an item, moving folders, or changing method/path breaks correlation: the next import creates instead of updates. This is a format limitation, shown in every preview. There is no stable exported per-item ID to use instead.

Duplicate keys inside one upload use the first request in Create/Update; later duplicates are skipped with warnings. Clone keeps all entries. When prior clones share a key, Update targets the oldest imported endpoint and warns. Exact signature duplicates are permitted for external imports/clones; normal priority/specificity/ID rules decide which endpoint answers. Adjust or disable duplicates after cloning.

Update preserves endpoint identity, enabled/priority settings, tags, environment overrides and runtime call state. It resets response selection to Weighted and replaces saved examples with equal-weight mapped responses; it does not preserve locally added conditions, templates, callbacks or faults in that response pool. Review the preview before confirming.

Updates record the endpoint pre-state plus its full previous response pool under one existing `import_batch_id`. **Undo this import** uses the same version-history action and confirmation dialog as native import. It restores the previous request, responses and rules and removes newly added examples; runtime counters remain untouched. Newly created endpoints have no pre-state and are not removed by history-based undo. No new import-history table is introduced.

## Portability and future adapters

Native **1.5** exports include nullable endpoint `external_source` (`type`, `correlation_key`, `spec_title`, `imported_at`) and nullable response `external_label`. Older native formats default both to null. Revisions preserve both fields. Runtime call state remains excluded from exports/imports.

`ImportMapper` defines the external mapped-document boundary; `PostmanMapper` owns Postman traversal, inheritance and warnings. `MappedImportService` owns cached previews, correlation analysis, transactions, collections, response replacement and revision batches using the shared `ImportPlan`/`ImportSummary`. A future OpenAPI mapper can implement the same contract and extend the existing format selector without copying this persistence or preview UI. No OpenAPI support is claimed in this release.

## Variable review, creation and resolution

Optionally expand **Also import a Postman Environment file** and upload its JSON `values[]`. The file's enabled values override collection/folder candidates; overrides are named in preview. Secret candidate values are masked. Review the unique candidate list, select an existing MockDeck Environment, adjust Secret toggles, and explicitly confirm **Create these as Environment variables**. Missing keys are created through the shared encrypted Environment writer; existing keys and values are never overwritten. Unknown or conflicting scoped values become empty placeholders. Names containing token, secret, password, key, credential or authorization default to Secret. Creation is separate from endpoint import and its batch undo.

**Exclude from matching** stays the import default. **Resolve using Environment** substitutes only header/query tokens from the selected MockDeck Environment's current values; those fields are then matched literally. Uploading a companion file alone does not write Environment values or silently resolve them. Create the reviewed variables first if needed. A missing value blocks confirmation until it is created or its field is switched to Exclude. Existing empty values are legitimate literal values. Per-request **Variable fields** disclosures allow each field to inherit the import default or override it with Exclude/Resolve. Request bodies, paths and response headers are not affected by this matching choice.

Resolved matching values are frozen at preview time. Changing an Environment later does not change the imported signature: re-preview and Update matching to adopt new literal values. Excluded fields remain insensitive to value changes. The full server-side candidate list is `MappedImportService`'s cached `document.variable_candidates`; public `ImportPlan.metadata.variable_candidates` is the same shape with secret values removed. A later pass can consume the mapped inventory directly without scanning source tokens again.

## Saved-response variable templates

Saved response bodies containing supported variables automatically become **Template** responses. `{{cartId}}` becomes `{{env.cartId}}` and resolves against the active Environment at serve time, independently of matching-resolution choices. Preview reports each mode change and the number of rewritten occurrences. Missing Environment values are render-time errors. Request bodies remain literal captured text with the existing warning.

JSON objects/arrays keep their structure; ordinary text/HTML examples use a JSON-string-root template and emit text without JSON quotation marks. The JSON editor stores that root as a quoted string; the visual Builder remains disabled for nonrepresentable/context templates. Existing JSON templates render identically. Original response Content-Type is retained. Conversion is compiled through the shared template engine; a body that cannot compile safely is retained static with a warning. Unknown dynamic tokens remain literal, including when mixed into an otherwise converted response.

### Dynamic-variable mapping

The translation table below is checked against `FakerMethodCatalog.resolve()`; it is not a second Faker catalog. `date.now` and `date.epochS` are added to that shared catalog to represent the current instant, rather than substituting a random recent date.

| Postman dynamic variable | MockDeck expression | Behaviour |
|---|---|---|
| `$guid` | `{{string.uuid}}` | UUID |
| `$randomUUID` | `{{string.uuid}}` | UUID |
| `$timestamp` | `{{date.epochS}}` | Current Unix seconds |
| `$isoTimestamp` | `{{date.now}}` | Current UTC ISO 8601 date/time |
| `$randomEmail` | `{{internet.email}}` | Generated email |
| `$randomInt` | `{{number.int(0,1000)}}` | Inclusive integer range 0–1000, interpolated as text |
| `$randomFirstName` | `{{person.firstName}}` | First name |
| `$randomLastName` | `{{person.lastName}}` | Last name |
| `$randomFullName` | `{{person.fullName}}` | Full name |
| `$randomCity` | `{{location.city}}` | City |
| `$randomCountry` | `{{location.country}}` | Country |

All other `$name` variables (for example `$someObscureVar`) are unmapped and warned by name; no guessed translation is applied. This pass does not change script inheritance, execution policy or script handling.
