# MockDeck user guide

This guide covers every currently implemented MockDeck feature and the normal operator workflow. It describes the application as shipped; planned features are listed separately under [Current limitations](#current-limitations).

## 1. Start and sign in

MockDeck is designed to run through Docker Compose. From the repository root:

```bash
cp .env.example .env
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
```

Copy the generated key into `.env`, replace the database and dashboard password placeholders, then run:

```bash
make up
make health
```

Open `http://localhost:18473/dashboard`. The dashboard uses the username and password configured by:

```dotenv
MOCK_DASHBOARD_AUTH_ENABLED=true
MOCK_DASHBOARD_USERNAME=admin
MOCK_DASHBOARD_PASSWORD=a-unique-dashboard-password
```

Login attempts are rate-limited. Use the user menu to sign out. Setting `MOCK_DASHBOARD_AUTH_ENABLED=false` is intended only for a trusted local environment.

## 2. Dashboard navigation and preferences

The dashboard contains:

- **Endpoints** — create, find, manage, duplicate, enable, disable, export, and delete mock endpoints.
- **Request log** — inspect matched, fallback, and unmatched requests.
- **Import / export** — move native MockDeck configuration between installations.
- **Documentation** — the in-app operator reference.

The theme control offers **System**, **Light**, and **Dark**. Light and Dark are stored in the browser; System removes the override and follows the operating system. Theme selection and request-log density persist across Livewire navigation and reloads without a wrong-theme flash. The browser favicon follows the system colour preference, while the in-app brand badge follows the effective dashboard theme.

Keyboard shortcuts are ignored while typing in a form control:

| Shortcut | Action |
|---|---|
| `N` | Open New endpoint |
| `/` | Focus the current endpoint or request-log search |
| `?` | Open shortcut help |
| `Ctrl+Enter` / `⌘+Enter` | Save the endpoint form |

## 3. Create an endpoint from cURL

1. Select **New endpoint**.
2. Replace the example with one complete absolute HTTP or HTTPS cURL command.
3. Review the parsed method, URL, query, headers, cookies, and body. MockDeck parses text and never executes the pasted command.
4. Set the endpoint name, enabled state, and integer priority.
5. Choose a matching policy:
   - include meaningful headers;
   - ignore `Cookie`;
   - ignore configured authentication headers;
   - ignore both cookies and authentication; or
   - ignore all headers.
6. Review the canonical request, signature variant, and SHA-256 hash.
7. Save the endpoint and configure at least one response.

Authentication is ignored by default for newly created endpoints. Transport metadata such as `Host`, `Content-Length`, `User-Agent`, `Accept`, `Connection`, and `X-Request-ID` is always excluded because clients and proxies can change it.

Absolute URLs are required during configuration, but scheme, host, and port are not part of the signature. An endpoint created from `https://api.example.test/v1/items` is invoked through the MockDeck host at `/v1/items`.

URL-embedded credentials, relative URLs, non-HTTP schemes, file-backed cURL bodies, and unsupported unsafe input are rejected.

## 4. Understand request matching

The canonical request contains the uppercase method, normalized path/query, selected lowercase headers, one blank line, and the canonical body. JSON object keys are sorted recursively; JSON array order is retained. Repeated query values keep their order.

| Variant | Included data | Specificity |
|---|---|---:|
| V1 | Method, path/query, meaningful headers, body | 50 |
| V2 | V1 without `Cookie` | 40 |
| V3 | V1 without configured authentication headers | 40 |
| V4 | V1 without cookies or authentication headers | 30 |
| V5 | Method, path/query, body only | 10 |

When more than one enabled endpoint matches, MockDeck chooses:

1. highest priority;
2. highest signature specificity;
3. lowest endpoint database ID as the stable tie-breaker.

Exact duplicate signatures are blocked. A broad header-free endpoint and a narrower header-aware endpoint can coexist; use priority to make an intentional override explicit.

Request-log match labels mean:

- **hash** — an incoming candidate hash matched the stored endpoint;
- **fallback** — canonical text matched after a stored hash drifted;
- **none** — no enabled endpoint matched.

## 5. Manage the endpoint registry

The endpoint toolbar can search name, method, path, or raw cURL. Filter by HTTP method and enabled state, then sort by recent update, name, or priority.

Each endpoint row supports:

- open/edit by selecting the row;
- enable or disable without deleting configuration;
- copy a mock-host cURL command;
- duplicate as a disabled endpoint;
- delete the endpoint and all of its responses.

Select one page or individual rows to expose bulk actions for **Enable**, **Disable**, **Export**, and **Delete**. Bulk delete requires confirmation. Pagination and selection are independent; verify the selection count before a destructive action.

### Collections and tags

Collections provide one optional folder-like grouping per endpoint. Tags are case-insensitive and many-to-many. Use the Collection dropdown and tag chips in the endpoint toolbar to filter the registry. Endpoint rows show the assigned collection and tags.

Select endpoint rows to expose **Move to collection** and **Add tags** in the bulk action bar. Deleting a collection does not delete its endpoints; their collection becomes empty. Tags can be reused across any collection.

## 6. Environments and variables

The active environment is global to the MockDeck server. The migration creates **Development** as the default and active environment, preserving existing endpoint behavior.

1. Open the header environment switcher.
2. Select an environment to make it active immediately.
3. Open **Manage environments** to create, rename, duplicate, or delete environments.
4. Add variables with a key, value, and optional Secret state.

Secret values are encrypted at rest through Laravel's encrypted cast, displayed as `••••••••`, and never returned in plaintext by GET or duplicate API responses. Leave a masked secret value blank while editing to retain it. Duplicating an environment creates independent variable records, including independent encrypted secret copies.

The server keeps exactly one default environment. Deleting the active or default environment requires selecting a different replacement first.

### Endpoint availability

The endpoint editor lists every environment. Each row can inherit the endpoint state, permit matching, or disable matching in that environment. Inheritance stores no override. An endpoint is eligible only when its own Enabled switch is on and the active environment has no override or an enabled override. Environment selection never changes signature generation or ranking.

Every new request-log event records the active environment. Entries created before this feature show `—`.

## 7. Configure response pools

An endpoint can have one or more responses. Each response has:

- HTTP status from 100 through 599;
- response headers as a JSON object;
- delay in milliseconds, capped by `MOCK_MAX_DELAY_MS`;
- a positive integer weight;
- Static or Template body mode.

Selection probability is proportional to weight. For example, weights `1` and `3` produce approximately 25% and 75% selection over many calls. MockDeck does not store records or scenario state, so the builder intentionally does not create a locked `id` field.

### Static body

Static text is returned exactly as stored. `$`, `{{`, and JSON-looking content have no special meaning. Set `Content-Type` explicitly when the caller requires one.

### Template body

Template mode parses JSON and renders it for every request. Successful templated responses default to `Content-Type: application/json` when that header is not configured. Preview and live serving share the same compiler and renderer.

## 8. Build a response template

Choose **Body → Template**. Set:

- **Builder** or **JSON** editor view;
- locale: `en`, `en_US`, `en_GB`, `fr_FR`, `de_DE`, `es_ES`, `it_IT`, or `ja_JP`;
- seed mode: Random, Fixed, or Request signature;
- an integer seed when Fixed is selected.

Validation and preview are debounced while editing. Review issue severity, JSON pointer, line/column, output bytes, and render time. Errors block Save; renamed-method warnings do not.

### Builder view

The builder represents simple object schemas or a list of objects. Start with the single empty row and add:

- **Faker.js** — choose a mapped catalog method; search is grouped by module and shows a sample;
- **String** — fixed or interpolated text;
- **Number** — fixed, random integer, or random float with bounds/decimals;
- **Boolean** — true, false, or random;
- **Date** — recent, past, future, between, or fixed, with output format;
- **Object** — collapsible nested child fields, up to six builder levels;
- **Array** — typed items, including objects, with fixed or ranged length.

Rows support nullable percentage, deletion, pointer controls, keyboard reordering, and catalog-driven argument fields. Raw JSON arguments are available when the guided fields are insufficient.

JSON text is the source of truth. Switching from Builder serializes with two-space indentation and row order. If JSON contains `$pick`, `$literal`, mixed interpolation, literal arrays, or another shape the builder cannot project losslessly, Builder is disabled and the JSON is never rewritten.

### JSON view and autocomplete

Type `$` in a token or `{{` inside a string to open catalog suggestions. Use Up/Down, Home/End, Enter or Tab, and Escape. Hovering or focusing an option exposes its sample. **Example** inserts a complete nested template.

The browser receives only catalog metadata. Parsing, validation, Faker calls, and rendering stay on the server.

## 9. Template language reference

### Typed calls

A string containing only a token returns its JSON-compatible type:

```json
{
  "id": "$number.int({\"min\":1,\"max\":100})",
  "enabled": "$datatype.boolean(100)",
  "name": "$person.fullName"
}
```

Numbers and booleans remain typed. Dates become ISO-8601 strings by default; unsupported function/symbol values are rejected. Inline arguments are comma-separated JSON values and are parsed as one JSON array.

### Interpolation

Use braces inside a larger string:

```json
{"message":"Hello {{person.firstName}}, order {{string.uuid}} is ready."}
```

Any number of tokens may appear in one string. Interpolated results are converted to text.

### Escapes

- `$$person.firstName` emits `$person.firstName`.
- `\{{person.firstName}}` emits literal `{{person.firstName}}`.
- `$100` and `$5.00` remain literal because they are not method-shaped tokens.
- A method-shaped unknown token is an error rather than silent text.

### Directive objects

```json
{
  "items": {
    "$repeat": {"min": 2, "max": 5},
    "$item": {
      "position": "$index",
      "sku": {"$faker": "string.alphanumeric", "$args": [{"length": 8}]},
      "tier": {"$pick": ["free", "pro"], "$weights": [3, 1]},
      "note": {"$maybe": 0.3, "$value": "{{lorem.sentence}}", "$else": null}
    }
  }
}
```

| Directive | Behavior |
|---|---|
| `$faker`, `$args`, `$format` | Long-form mapped Faker call. Date format is `iso`, `date`, `epochMs`, or `epochS`. |
| `$repeat`, `$item` | Emit an array using a fixed count or `{min,max}` range. |
| `$pick`, `$weights` | Select one value, optionally using matching positive weights. |
| `$maybe`, `$value`, `$else` | Emit the value with probability 0–1; else defaults to `null`. |
| `$literal` | Emit the nested JSON without processing tokens or directives. |

Inside `$repeat`, `$index` returns a typed zero-based integer and `{{$index}}` interpolates it into text. Any unsupported dollar-prefixed object key is a validation error.

### Renamed Faker.js methods

Legacy Faker.js names render through aliases and produce `RENAMED_METHOD` warnings with quick fixes:

| Legacy | Current |
|---|---|
| `name.*` | `person.*` |
| `address.*` | `location.*` |
| `datatype.uuid` | `string.uuid` |
| `datatype.number` | `number.int` |
| `datatype.float` | `number.float` |
| `internet.userName` | `internet.username` |
| `phone.phoneNumber` | `phone.number` |
| `company.companyName` | `company.name` |
| `person.findName`, `name.findName` | `person.fullName` |

The backend maps the supported Faker.js-shaped catalog to `fakerphp/faker` 1.24. It does not install `@faker-js/faker` or execute JavaScript templates.

### Safety and limits

Templates are parsed without `eval`, `new Function`, or a VM. Resolution uses a fixed module/method catalog and rejects `__proto__`, `constructor`, and `prototype`. Callback/unbounded helpers are blocked. `helpers.fromRegExp` is additionally bounded.

Default limits:

| Limit | Default |
|---|---:|
| Template size | 256 KiB |
| Template depth | 12 |
| Parsed nodes | 10,000 |
| Rendered nodes | 100,000 |
| `$repeat` per directive | 1,000 |
| Inline/long-form arguments | 4 KiB |
| Rendered output | 1 MiB |

Tune them with the `MOCK_TEMPLATE_*` variables in `.env.example`.

## 10. Seeds, locales, and regeneration

- **Random** does not explicitly seed the provider. Regenerate can produce different values.
- **Fixed** resets the provider to the configured integer at the start of every render.
- **Request signature** derives an integer from the matched request hash. The same normalized request produces the same output.
- **Locale** selects one of the server-supported FakerPHP providers.

The selected locale is applied to both preview and live serving. Seed settings are stored per response and are included in export/import.

## 11. Template validation and runtime failures

| Code | Meaning |
|---|---|
| `INVALID_JSON` | Correct JSON syntax or root shape. |
| `UNKNOWN_METHOD` | Choose a catalog method or suggestion. |
| `RENAMED_METHOD` | Alias works; apply the offered current name when convenient. |
| `BLOCKED_METHOD` | Method is intentionally unavailable for safety/boundedness. |
| `BAD_ARGS` | Correct arguments, seed, format, or directive structure. |
| `RESERVED_KEY` | Remove the unsupported dollar-prefixed key. |
| `LIMIT_EXCEEDED` | Reduce input depth/size/nodes/repeats/arguments/output. |

A runtime failure returns HTTP 500:

```json
{
  "error": "template_render_failed",
  "path": "/items/0/name",
  "token": "$person.unknown",
  "message": "Safe error description"
}
```

The response also contains `X-MockDeck-Template-Error: 1`. Logs record the error metadata, `templated`, and render time, but never the full rendered body.

## 12. Invoke a mock

Select **Copy mock curl** from an endpoint row. MockDeck replaces the source origin with `APP_URL` and preserves method, path, query, meaningful headers, and body.

```bash
curl 'http://localhost:18473/v1/items?limit=10' \
  -H 'X-Request-ID: example-001' \
  -H 'Content-Type: application/json' \
  --data '{"name":"Example"}'
```

Every returned response includes `X-Request-ID`. A caller-provided ID matching the accepted format is retained; otherwise MockDeck generates a UUID.

Serving preserves the selected response status, headers, and delay. No enabled match returns diagnostic JSON 404. A matched endpoint without responses returns diagnostic JSON 500. Request-normalization failures return diagnostic JSON 400.

## 13. Inspect the request log

Open **Request log**. Search by path, endpoint, or request ID, and filter by method, match, status family, endpoint, time range, and row limit. **Unmatched only** narrows to requests with match `none`; **Group repeats** collapses consecutive identical events.

Choose local or UTC timestamps and Compact or Comfortable density. Density is stored in the current browser. Expand a row to inspect canonical data, response selection, matching details, and nearest candidates. An unmatched row can be used to start a new endpoint from reconstructed cURL.

Logs are flat JSON written to stdout and/or daily files. They are not stored in PostgreSQL. File reading is bounded across current and rotated logs, and malformed/partial lines are skipped.

## 14. Export and import configuration

Open **Import / export**.

### Export

1. Choose all endpoints, one collection, or one environment, then search/select endpoints or select all in that scope.
2. Keep **Redact request secrets** enabled for normal sharing.
3. Export the selected set.

Redaction removes configured authentication/cookie/API-key headers and sensitive query values. An affected endpoint exports disabled with `requires_secret_replacement: true`. Template text is exported exactly as stored and is not redacted. Environment secrets are always exported with a null value, even when request-secret redaction is disabled.

Environment-scoped export includes endpoints that inherit their state and endpoints with an enabled override; it excludes explicit disabled overrides.

### Import

1. Choose a `.json` MockDeck file.
2. Choose **Create only**, **Update by UUID**, or **Clone with new UUIDs**.
3. Preview. Preview performs no writes.
4. Review validation errors, exact-signature conflicts, overlap warnings, UUID actions, and redaction warnings.
5. Acknowledge warnings and apply. Apply rechecks the digest/conflicts under locks and writes atomically.

For **Update by UUID**, preview also reports how many endpoint/response pre-states will receive a revision. Every changed existing entity is snapshotted under one import batch before its imported values replace the live values. The success panel keeps **Undo this import** available; one confirmation lists the affected entities and restores all recorded pre-states atomically. The undo itself appends rollback revisions, so it does not erase evidence of the import.

Update-by-UUID merges responses by UUID. Enable response replacement only when the imported list should delete omitted local responses. Version 1/1.0/1.1 files remain supported; missing template and organization fields use backward-compatible defaults. Current exports use format `1.2`.

CLI equivalents:

```bash
php artisan mockdeck:export --output=mockdeck.json
php artisan mockdeck:export --endpoint=<uuid> --output=subset.json
php artisan mockdeck:export --include-sensitive --output=private.json
php artisan mockdeck:import mockdeck.json --dry-run --json
php artisan mockdeck:import mockdeck.json --mode=upsert --acknowledge-warnings
```

See [CONFIG_IMPORT_EXPORT.md](CONFIG_IMPORT_EXPORT.md) for the complete document and conflict contract.

## 15. Version history, diff, and restore

Every endpoint and response edit that changes persisted fields stores the complete pre-save state. No-op saves do not create noise. Endpoint snapshots include collection, tag IDs, and per-environment overrides; response snapshots include body/template and selection behavior.

Open **History** in the endpoint editor or on a response row:

1. Select two stored versions to open a structural before/after diff.
2. Choose **Compare with current** on any revision to compare it with the live entity using the same viewer and response shape.
3. Review canonical-request changes in the canonical code surface, response body/template changes in JSON code surfaces, and organization changes as added/removed values.
4. Choose **Restore**, review the confirmation naming the version, and confirm. MockDeck applies that snapshot and appends a new revision with source `rollback`; it never mutates or deletes earlier history.

Revision summaries are intentionally short (for example, `renamed`, `priority 0→5`, or `response body changed`). The detailed viewer remains authoritative.

## 16. Protected APIs

### Protected template endpoints

The dashboard uses the following protected template endpoints and organization APIs:

| Method and path | Purpose |
|---|---|
| `GET /api/faker-catalog` | Mapped catalog, return types, samples, aliases, and argument hints; supports ETag. |
| `POST /api/response-templates/validate` | Parse/validate template and return structured issues. |
| `POST /api/response-templates/preview` | Render output and return bytes, render time, and issues. |
| `GET/POST/PATCH/DELETE /api/collections` | Manage endpoint collections. |
| `GET/POST/PATCH/DELETE /api/tags` | Manage case-insensitive endpoint tags. |
| `GET/POST/PATCH/DELETE /api/environments` | Manage environments; secret values are masked. |
| `POST /api/environments/{id}/duplicate` | Create an independent environment and variable copy. |
| `GET/POST/PATCH/DELETE /api/environments/{id}/variables` | Manage write-only secret and public variables. |
| `GET/PUT /api/active-environment` | Read or switch the global active environment. |
| `PATCH /api/endpoints/{id}/organization` | Update collection, tags, and environment overrides. |
| `GET /api/{endpoints|responses}/{id}/revisions` | List immutable revisions newest first with summaries. |
| `GET /api/{endpoints|responses}/{id}/revisions/{a}/diff/{b}` | Structurally diff two stored versions. |
| `GET /api/{endpoints|responses}/{id}/revisions/{revision}/diff/current` | Diff one version against live state. |
| `POST /api/{endpoints|responses}/{id}/revisions/{revision}/restore` | Restore as a new rollback revision. |
| `GET /api/imports/{batch_id}` | Preview the entities in a reversible import batch. |
| `POST /api/imports/{batch_id}/undo` | Restore every recorded pre-import state atomically. |

They require dashboard access and are intended for the built-in editor, not as unauthenticated public APIs.

## 17. Operations and validation

```bash
make up                 # build, start, and wait for services
make health             # verify /up
make logs               # follow app and nginx logs
make shell              # shell in the running app container
make down               # stop services without deleting named volumes
make validate           # Compose, token, frontend, build, Composer, Pint, PHPUnit
make format             # apply Pint to the bind-mounted working tree
```

The app runs migrations on container startup when `RUN_MIGRATIONS=true`. Before pushing, run `make format` followed by `make validate`.

Git must retain the `.gitignore` placeholders under `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, and `storage/logs`. Without them, a clean checkout can fail during Composer `package:discover` with `Please provide a valid cache path.`

See [VALIDATION.md](VALIDATION.md) for complete automated and manual release checks.

## 18. Current limitations

- Matching uses five fixed header policies; arbitrary per-field query/header/body predicates are not implemented.
- Upstream origin is intentionally excluded from signatures.
- Response selection is weighted random, not stateful scenarios or request-rule selection.
- Templates currently serve JSON only. Request-context tokens and non-JSON interpolation were not shipped.
- Builder is intentionally a lossless subset of the JSON template language.
- OpenAPI generation/import, recording/proxying, verification assertions, and third-party format adapters remain planned.

For implementation boundaries, read [ARCHITECTURE.md](ARCHITECTURE.md). For UI changes, read [UI_GUIDE.md](UI_GUIDE.md).
