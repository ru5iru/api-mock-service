<x-layouts.dashboard title="Documentation">
    <x-page-header title="Documentation" description="Set up exact-request mocks, dynamic response templates, configuration transfers, and request-log troubleshooting.">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
        </x-slot:actions>
    </x-page-header>

    <div class="docs-grid">
        <nav class="card docs-card docs-wide" aria-labelledby="docs-contents">
            <h2 id="docs-contents">Contents</h2>
            <ol class="docs-toc">
                <li><a href="#getting-started">Create an endpoint</a></li>
                <li><a href="#endpoint-registry">Manage endpoints</a></li>
                <li><a href="#environments">Collections, tags, and environments</a></li>
                <li><a href="#request-matching">Request matching</a></li>
                <li><a href="#response-pools">Response pools</a></li>
                <li><a href="#response-templating">Response templating</a></li>
                <li><a href="#template-language">Template language</a></li>
                <li><a href="#builder-view">Builder view</a></li>
                <li><a href="#json-view">JSON view</a></li>
                <li><a href="#template-seeding">Seeds and locales</a></li>
                <li><a href="#template-errors">Validation and errors</a></li>
                <li><a href="#config-transfer">Import and export</a></li>
                <li><a href="#version-history">Version history and restore</a></li>
                <li><a href="#request-log">Request log</a></li>
                <li><a href="#preferences-accessibility">Theme and accessibility</a></li>
                <li><a href="#serving-errors">Serving behavior</a></li>
                <li><a href="#shortcuts">Keyboard shortcuts</a></li>
            </ol>
        </nav>

        <section id="getting-started" class="card docs-card docs-wide">
            <h2>Create an endpoint</h2>
            <ol>
                <li>Select <strong>New endpoint</strong> and paste a complete cURL request. MockDeck parses the method, URL, query, headers, cookies, and body without executing it.</li>
                <li>Review the generated endpoint name and matching options. Remove or mask secrets before saving if the cURL contains credentials.</li>
                <li>Select which request parts participate in the signature. More included data produces a stricter match.</li>
                <li>Save the endpoint, open its response pool, and add at least one static or templated response.</li>
                <li>Copy the MockDeck cURL from the endpoint list and call it to verify the result.</li>
            </ol>
            <h3>Endpoint state and priority</h3>
            <p>Disabled endpoints never participate in matching. When multiple enabled endpoints could match, the highest priority wins. If priorities are equal, the more specific signature wins.</p>
        </section>

        <section id="endpoint-registry" class="card docs-card docs-wide">
            <h2>Manage endpoints</h2>
            <p>Search endpoint name, method, path, or raw cURL. Filter by method and enabled state, then sort by recent update, name, or priority.</p>
            <ul>
                <li>Select a row to edit its request and response pool.</li>
                <li>Use the row menu to enable/disable, duplicate, copy the mock-host cURL, or delete.</li>
                <li>Duplicated endpoints start disabled so they cannot immediately compete with the source signature.</li>
                <li>Select individual rows or the current page for bulk enable, disable, export, or delete.</li>
                <li>Disabled endpoints retain their configuration but never participate in matching.</li>
            </ul>
        </section>

        <section id="request-matching" class="card docs-card">
            <h2>Request matching</h2>
            <dl class="definition-list">
                <div><dt>Signature</dt><dd>The canonical method, path, query, selected headers, and body used to identify a request.</dd></div>
                <div><dt>Signature version</dt><dd>The matching-policy variant. V1 includes all supported request data selected in the policy.</dd></div>
                <div><dt>Match type</dt><dd>Hash is exact, fallback is normalized recovery, and none means no endpoint matched.</dd></div>
                <div><dt>Hash</dt><dd>The incoming canonical request produced the stored signature hash.</dd></div>
                <div><dt>Fallback</dt><dd>A normalized stored signature matched after the hash did not. Inspect the request log for drift.</dd></div>
                <div><dt>None</dt><dd>No enabled endpoint matched the request.</dd></div>
            </dl>
        </section>

        <section id="environments" class="card docs-card docs-wide">
            <h2>Collections, tags, and environments</h2>
            <p>Collections and tags organize endpoints without changing request signatures. Filter by collection or tag chips, show assignments on endpoint rows, and use the bulk bar to move or tag selected endpoints. Deleting a collection leaves its endpoints in place.</p>
            <h3>Active environment</h3>
            <ol>
                <li>Choose the global active environment from the header switcher.</li>
                <li>Open <strong>Manage environments</strong> to create, rename, duplicate, delete, or choose the default.</li>
                <li>Add variables. Secret values are write-only after saving and always masked or redacted.</li>
                <li>Use the endpoint editor's Environment availability table for explicit overrides. Inherit stores no override.</li>
            </ol>
            <p>An endpoint matches only when it is enabled and the active environment has no override or an enabled override. This eligibility check does not change request hashes or precedence. New request-log entries show the active environment.</p>
        </section>

        <section id="response-pools" class="card docs-card">
            <h2>Response pools</h2>
            <p>An endpoint can contain multiple responses. Each response keeps its own status, headers, delay, weight, body mode, and body.</p>
            <ul>
                <li><strong>Status:</strong> the HTTP status returned to the client.</li>
                <li><strong>Headers:</strong> a JSON object returned with the selected response.</li>
                <li><strong>Delay:</strong> simulated latency in milliseconds, capped by the server limit.</li>
                <li><strong>Weight:</strong> relative selection probability when the pool has multiple responses.</li>
                <li><strong>Static:</strong> returned exactly as stored; dollar signs and braces remain literal.</li>
                <li><strong>Template:</strong> parsed and rendered for every matching request.</li>
            </ul>
        </section>

        <section id="response-templating" class="card docs-card docs-wide">
            <h2>Response templating</h2>
            <p>Choose <strong>Body → Template</strong> to generate response JSON with Faker data. Templating is opt-in per response; existing static responses are unchanged. Preview and live serving use the same server-side parser and renderer.</p>
            <p>The backend maps supported Faker.js-shaped method names to FakerPHP 1.24. No Faker runtime or template evaluator is shipped to the browser. Successful templates default to <code>application/json</code> when the response does not define Content-Type.</p>
            <h3>Workflow</h3>
            <ol>
                <li>Choose <strong>Builder</strong> for a simple schema or <strong>JSON</strong> for the complete language.</li>
                <li>Select a locale and seed mode. Fixed and request-signature seeds make output repeatable.</li>
                <li>Build or type the template. Validation identifies errors by JSON pointer, line, and column.</li>
                <li>Review the preview, byte count, render time, warnings, and errors. Use <strong>Regenerate</strong> to render again.</li>
                <li>Save when no error issues remain. Warnings do not block saving.</li>
            </ol>
            <h3>Complete example</h3>
            <div class="code-block-wrap docs-example">
                @verbatim
<pre>{
  "username": "$internet.userName",
  "knownIps": ["$internet.ip", "$internet.ipv6"],
  "profile": {
    "firstName": "$name.firstName",
    "lastName": "$name.lastName",
    "staticData": [100, 200, 300]
  }
}</pre>
                @endverbatim
            </div>
            <p>Legacy names still render, but validation reports a renamed-method warning and offers the current name as a quick fix.</p>
        </section>

        <section id="template-language" class="card docs-card docs-wide">
            <h2>Template language</h2>
            <h3>Typed Faker values</h3>
            <p>A string containing only a Faker token returns the method's native JSON-compatible type. Numbers and booleans remain numbers and booleans.</p>
            <div class="code-block-wrap docs-example">
                @verbatim
<pre>{
  "id": "$number.int({\"min\":1,\"max\":100})",
  "active": "$datatype.boolean(100)",
  "name": "$person.fullName"
}</pre>
                @endverbatim
            </div>
            <p>Inline arguments are comma-separated JSON values. The combined argument text is limited to 4 KB.</p>

            <h3>String interpolation</h3>
            <p>Use double braces inside a larger string. Interpolated values are converted to text.</p>
            <div class="code-block-wrap docs-example">
                @verbatim
<pre>{"message":"Hello {{person.firstName}}, order {{string.uuid}} is ready."}</pre>
                @endverbatim
            </div>

            <h3>Escapes and literal values</h3>
            <ul>
                <li><code>$$person.firstName</code> emits <code>$person.firstName</code>.</li>
                <li><code>@verbatim\{{person.firstName}}@endverbatim</code> emits literal double braces.</li>
                <li><code>$100</code> and <code>$5.00</code> remain literal because they do not match a Faker token.</li>
                <li>Use <code>$literal</code> when an entire JSON value must bypass processing.</li>
            </ul>

            <h3>Directive objects</h3>
            <dl class="definition-list">
                <div><dt><code>$faker</code></dt><dd>Long-form Faker call with optional <code>$args</code> and date <code>$format</code>.</dd></div>
                <div><dt><code>$repeat</code></dt><dd>Creates an array using a fixed count or a min/max range and an <code>$item</code> template.</dd></div>
                <div><dt><code>$pick</code></dt><dd>Selects one value, optionally using matching positive <code>$weights</code>.</dd></div>
                <div><dt><code>$maybe</code></dt><dd>Uses <code>$value</code> with the given probability and otherwise <code>$else</code>, defaulting to null.</dd></div>
                <div><dt><code>$literal</code></dt><dd>Emits its value without interpreting nested tokens or directives.</dd></div>
            </dl>
            <div class="code-block-wrap docs-example">
                @verbatim
<pre>{
  "items": {
    "$repeat": {"min": 2, "max": 5},
    "$item": {
      "position": "$index",
      "sku": {"$faker": "string.alphanumeric", "$args": [{"length": 8}]},
      "tier": {"$pick": ["free", "pro"], "$weights": [3, 1]},
      "note": {"$maybe": 0.3, "$value": "{{lorem.sentence}}", "$else": null}
    }
  }
}</pre>
                @endverbatim
            </div>
            <p>Inside <code>$repeat</code>, use <code>$index</code> for a typed zero-based number or <code>@verbatim{{$index}}@endverbatim</code> inside text. A repeat cannot exceed 1,000 items.</p>
            <h3>Default limits</h3>
            <p>Templates are limited to 256 KiB, depth 12, 10,000 parsed nodes, 100,000 rendered nodes, 1,000 items per repeat, 4 KiB of arguments, and 1 MiB of rendered output. Operators can tighten these limits with the <code>MOCK_TEMPLATE_*</code> environment variables.</p>
        </section>

        <section id="builder-view" class="card docs-card">
            <h2>Builder view</h2>
            <ul>
                <li>Choose an object root or a list of objects with fixed or ranged length.</li>
                <li>Add Faker.js, String, Number, Boolean, Date, Object, or Array fields.</li>
                <li>Search Faker methods by module or method; samples appear beside results.</li>
                <li>Use the settings control for guided or raw JSON arguments.</li>
                <li>Set nullable percentage, reorder fields, and nest objects up to six builder levels.</li>
            </ul>
            <p>JSON is the source of truth. If it contains a structure the builder cannot represent losslessly, Builder is disabled and the original JSON is preserved.</p>
        </section>

        <section id="json-view" class="card docs-card">
            <h2>JSON view</h2>
            <ul>
                <li>Type <code>$</code> or <code>{{ '{{' }}</code> to open Faker suggestions.</li>
                <li>Use Up/Down to move, Enter or Tab to insert, and Escape to close.</li>
                <li>Hover a suggestion to inspect its sample value.</li>
                <li>Select <strong>Example</strong> to insert a working nested template.</li>
                <li>Apply renamed-method and “did you mean” quick fixes from the issue list.</li>
            </ul>
        </section>

        <section id="template-seeding" class="card docs-card">
            <h2>Seeds and locales</h2>
            <dl class="definition-list">
                <div><dt>Random</dt><dd>Does not set a seed; repeated renders can differ.</dd></div>
                <div><dt>Fixed</dt><dd>Uses the configured integer for repeatable output.</dd></div>
                <div><dt>Request signature</dt><dd>Derives a seed from the matched signature so the same normalized request produces the same output.</dd></div>
                <div><dt>Locale</dt><dd>Selects a server-supported Faker provider locale.</dd></div>
            </dl>
        </section>

        <section id="template-errors" class="card docs-card">
            <h2>Validation and runtime errors</h2>
            <ul>
                <li><strong>INVALID_JSON:</strong> correct JSON syntax.</li>
                <li><strong>UNKNOWN_METHOD:</strong> choose a catalog method or suggestion.</li>
                <li><strong>RENAMED_METHOD:</strong> the alias works; a quick fix provides the current name.</li>
                <li><strong>BLOCKED_METHOD:</strong> the method accepts unsafe callbacks or unbounded work.</li>
                <li><strong>BAD_ARGS:</strong> correct method arguments or directive structure.</li>
                <li><strong>RESERVED_KEY:</strong> remove unsupported dollar-prefixed keys.</li>
                <li><strong>LIMIT_EXCEEDED:</strong> reduce template size, depth, nodes, repeats, arguments, or output.</li>
            </ul>
            <p>A serving failure returns HTTP 500 with <code>template_render_failed</code>, its JSON-pointer path, token, and safe message, plus <code>X-MockDeck-Template-Error: 1</code>. Logs contain metadata, never the rendered body.</p>
        </section>

        <section id="config-transfer" class="card docs-card docs-wide">
            <h2>Import and export</h2>
            <h3>Export</h3>
            <ol>
                <li>Choose all endpoints, one collection, or one environment, then search/select endpoints or export the complete scope.</li>
                <li>An environment scope includes inherited endpoints and enabled overrides; an explicit disabled override excludes an endpoint.</li>
                <li>Keep secret redaction enabled unless original credentials are required. Redacted endpoints export disabled.</li>
                <li>Environment secrets are always redacted, even in an otherwise unredacted export. Templates remain exact.</li>
            </ol>
            <h3>Import</h3>
            <ol>
                <li>Choose a MockDeck JSON file and select Create only, Upsert, or Clone.</li>
                <li>Preview the complete plan; preview never writes to the database.</li>
                <li>Resolve errors and review warnings. Upsert replaces response pools only when explicitly enabled.</li>
                <li>Confirm to apply atomically. Version 1 files remain supported and missing template fields become static responses.</li>
                <li>Update-by-UUID preview shows how many endpoint/response pre-states will be versioned. After apply, Undo this import restores the complete recorded batch.</li>
            </ol>
        </section>

        <section id="version-history" class="card docs-card docs-wide">
            <h2>Version history, compare, and restore</h2>
            <p>Every changed endpoint/response save records its complete pre-state; no-op saves do not add revisions. Endpoint history includes collection, tags, and environment overrides.</p>
            <ol>
                <li>Open <strong>History</strong> in the endpoint editor or on a response row.</li>
                <li>Select two versions for a structural diff, or choose <strong>Compare with current</strong> to compare one saved version with live values.</li>
                <li>Review canonical request, JSON body/template, and organization changes in the shared before/after viewer.</li>
                <li>Choose <strong>Restore</strong> and confirm the named version. Restore changes live values and appends a new rollback revision; it never rewrites existing history.</li>
            </ol>
            <p>An Update-by-UUID import groups all changed entity pre-states under one batch. Its success panel keeps <strong>Undo this import</strong> available and lists every affected endpoint/response before restoring them atomically.</p>
        </section>

        <section id="request-log" class="card docs-card">
            <h2>Request log</h2>
            <p>Search and filter by path, endpoint, request ID, method, match, status, and time. Expand a row to inspect canonical data and nearest candidates.</p>
            <ul>
                <li>Templated entries include render time but not the rendered body.</li>
                <li>Create an endpoint directly from an unmatched request's reconstructed cURL.</li>
                <li>Filter by method, match, status, endpoint, time range, row limit, or unmatched-only.</li>
                <li>Group consecutive repeats and choose local/UTC clock plus Compact/Comfortable density.</li>
                <li>Density persists in the current browser; the Local/UTC clock choice applies to the current log view.</li>
            </ul>
        </section>

        <section id="preferences-accessibility" class="card docs-card">
            <h2>Theme and accessibility</h2>
            <ul>
                <li>Choose System, Light, or Dark from the header. Explicit choices persist in this browser; System follows live operating-system changes.</li>
                <li>The in-app brand icon follows the effective dashboard theme. The browser favicon follows the browser/system colour preference.</li>
                <li>All primary actions, menus, disclosures, template suggestions, dialogs, and row controls are keyboard accessible.</li>
                <li>Focus indicators, semantic status colours, reduced-motion behavior, and light/dark contrast are provided by the shared design system.</li>
            </ul>
        </section>

        <section id="serving-errors" class="card docs-card docs-wide">
            <h2>Serving behavior</h2>
            <p><strong>Copy mock cURL</strong> replaces the upstream origin with the configured MockDeck <code>APP_URL</code>. Scheme, host, and port do not affect matching; the method, normalized path/query, selected headers, and body do.</p>
            <dl class="definition-list">
                <div><dt>Configured response</dt><dd>Returns its selected status, headers, body, and bounded delay with an <code>X-Request-ID</code>.</dd></div>
                <div><dt>400</dt><dd>The incoming request could not be normalized safely.</dd></div>
                <div><dt>404</dt><dd>No enabled endpoint matched.</dd></div>
                <div><dt>500</dt><dd>The endpoint has no response, a saved template failed, or an unexpected server error occurred.</dd></div>
            </dl>
            <p>A valid caller-provided <code>X-Request-ID</code> is retained; otherwise MockDeck generates a UUID. Logging failures are isolated and cannot replace a configured mock response.</p>
        </section>

        <section id="shortcuts" class="card docs-card">
            <h2>Keyboard shortcuts</h2>
            <dl class="shortcut-list">
                <div><dt><kbd>N</kbd></dt><dd>Create a new endpoint</dd></div>
                <div><dt><kbd>/</kbd></dt><dd>Focus endpoint or log search</dd></div>
                <div><dt><kbd>?</kbd></dt><dd>Open shortcut help</dd></div>
                <div><dt><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Enter</kbd></dt><dd>Save the endpoint form</dd></div>
            </dl>
        </section>
    </div>
</x-layouts.dashboard>
