<x-layouts.dashboard title="Documentation">
    <x-page-header title="Documentation" description="Start the service, configure HTTP/HTTPS, build mocks, and troubleshoot requests.">
        <x-slot:actions>
            <a class="button button-primary" href="{{ route('dashboard.endpoints.create') }}" wire:navigate>New endpoint</a>
        </x-slot:actions>
    </x-page-header>

    <div class="docs-grid">
        <div class="card docs-searchbar docs-wide" role="search" aria-label="Documentation">
            <label class="field"><span>Search documentation</span><input type="search" data-docs-search placeholder="Search commands, matching, callbacks…" aria-describedby="docs-results"></label>
            <label class="field"><span>Section</span><select class="ui-select" data-docs-jump><option value="">Jump to a section…</option></select></label>
            <button class="text-button" type="button" data-docs-clear>Clear search</button>
            <span id="docs-results" class="field-help" role="status" data-docs-results></span>
        </div>
        <nav class="card docs-card docs-wide" aria-labelledby="docs-contents">
            <h2 id="docs-contents">Contents</h2>
            <ol class="docs-toc">
                <li><a href="#service-startup">First-time service setup</a></li>
                <li><a href="#local-https">HTTP and HTTPS on one port</a></li>
                <li><a href="#connection-troubleshooting">Connection and certificate troubleshooting</a></li>
                <li><a href="#getting-started">Create an endpoint</a></li>
                <li><a href="#endpoint-registry">Manage endpoints</a></li>
                <li><a href="#environments">Collections, tags, and environments</a></li>
                <li><a href="#request-matching">Request matching</a></li>
                <li><a href="#response-pools">Response pools</a></li>
                <li><a href="#wave-two">Field exclusions, path parameters and faults</a></li>
                <li><a href="#verification">CI verification</a></li>
                <li><a href="#isolated-test-runs">Isolated test runs</a></li>
                <li><a href="#response-templating">Response templating</a></li>
                <li><a href="#template-language">Template language</a></li>
                <li><a href="#builder-view">Builder view</a></li>
                <li><a href="#json-view">JSON view</a></li>
                <li><a href="#template-seeding">Seeds and locales</a></li>
                <li><a href="#template-errors">Validation and errors</a></li>
                <li><a href="#config-transfer">Import and export</a></li>
                <li><a href="#version-history">Version history and restore</a></li>
                <li><a href="#request-log">Request log</a></li>
                <li><a href="#async-callbacks">Asynchronous callbacks</a></li>
                <li><a href="#preferences-accessibility">Theme and accessibility</a></li>
                <li><a href="#serving-errors">Serving behavior</a></li>
                <li><a href="#shortcuts">Keyboard shortcuts</a></li>
                <li><a href="#licensing">License and third-party notices</a></li>
            </ol>
        </nav>

        <section id="service-startup" class="card docs-card docs-wide">
            <h2>First-time service setup</h2>
            <p>Install Docker Engine/Desktop with Compose v2, Make, Python 3 and curl. On Windows, enable Docker Desktop integration for WSL. PHP, Composer and PostgreSQL run in containers. Run Make commands from the repository root in Bash/WSL; Windows certificate-trust commands below run in PowerShell.</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>make setup
# Read the generated dashboard credentials in .env.
make up
make health
make status</code></pre></div>
            <p>Setup creates a missing <code>.env</code> and generates missing placeholder secrets. For a new file without a complete certificate pair it selects certificate-free HTTP and generates no certificates. Existing <code>.env</code> values and protocol selection are preserved; run <code>make http-enable</code> if needed before <code>make up</code> to select HTTP only. Read <code>MOCK_DASHBOARD_USERNAME</code> and <code>MOCK_DASHBOARD_PASSWORD</code> in that file to sign in. Setup and help output show the effective Compose files, APP_URL scheme and next command without secrets. Keep existing secrets when updating. Migrations run automatically. Open <code>http://localhost:18473/dashboard</code>, create/import an endpoint and add a response before calling it.</p>
            <p>For a custom port, edit both <code>APP_PORT</code> and <code>APP_URL</code> before startup. Health checks use the configured origin. Run <code>make help</code> for the command menu. Use <code>make up</code> after source changes, <code>make restart</code> after environment changes, <code>make logs</code> to inspect failures, and <code>make down</code> to stop while retaining data.</p>
            <p>For release checks, install Node.js 22 and run <code>make validate</code>. Run <code>make browser-install</code>, then <code>MOCKDECK_BASE_URL=http://localhost:18473 make browser-test</code> against an accessible test dashboard. These checks cover both themes; they require dashboard access.</p>
            <p>Direct Compose remains available for advanced diagnostics; the startup equivalent is:</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>docker compose config --quiet
docker compose up -d --build --wait</code></pre></div>
        </section>

        <section id="local-https" class="card docs-card docs-wide">
            <h2>HTTP and HTTPS on one port</h2>
            <p>With the HTTPS override enabled, both <code>http://localhost:18473</code> and <code>https://localhost:18473</code> serve the same application without redirects. Scheme, host and port do not change mock signatures. Certificates are generated locally, mounted read-only, and never supplied with MockDeck.</p>
            <p>After <code>make setup</code>, install mkcert, then run the following in Bash/WSL:</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>make https-setup
make up
make health
make https-check</code></pre></div>
            <p>The helper installs the local CA, generates certificates only when both files are absent, and enables the override. Existing pairs are preserved; incomplete pairs require repair. It does not install mkcert or trust a WSL CA in Windows. Use <code>make https-enable</code> for existing certificates, or <code>make http-enable</code> for HTTP only, then <code>make up</code> to apply.</p>
            <h3>Generate in WSL/Ubuntu</h3>
            <div class="code-block-wrap docs-example template-preview"><pre><code>sudo apt update
sudo apt install mkcert libnss3-tools
# Manual certificate-generation alternative:
mkcert -install
mkdir -p docker/nginx/certs
mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1
ls -l docker/nginx/certs</code></pre></div>
            <p>Both <code>localhost.pem</code> and <code>localhost-key.pem</code> must exist. Use forward slashes in Bash paths. Run mkcert as the same Linux user each time: root and a normal user use different CA directories.</p>
            <details class="normalized-details" data-disclosure>
                <summary><x-disclosure-chevron /> Alternative: generate in Windows PowerShell</summary>
                <p>Follow the <a href="https://github.com/FiloSottile/mkcert#installation">mkcert installation instructions</a> for Windows and run these commands in the repository folder. This trusts the Windows-generated CA on Windows; WSL clients need that same CA separately.</p>
                <div class="code-block-wrap docs-example template-preview"><pre><code>New-Item -ItemType Directory -Force docker/nginx/certs
mkcert -install
mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1</code></pre></div>
            </details>
            <h3>Trust a WSL-generated CA in Windows Chrome</h3>
            <p>Installing a CA in WSL does not trust it in Windows. In WSL, as the user that generated the certificate, replace <code>YOUR_WINDOWS_USER</code> with your Windows profile folder name:</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>cp "$(mkcert -CAROOT)/rootCA.pem" /mnt/c/Users/YOUR_WINDOWS_USER/Desktop/mockdeck-rootCA.pem</code></pre></div>
            <p>Then run in Windows PowerShell as the user running the browser:</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>certutil -user -addstore Root "$env:USERPROFILE\Desktop\mockdeck-rootCA.pem"</code></pre></div>
            <p>Close all Chrome windows and reopen Chrome. Import only your own public <code>rootCA.pem</code>; never copy/share <code>rootCA-key.pem</code>. Trust the CA that issued the server certificate, not a newly generated unrelated CA. Updating browser trust needs no application rebuild or Nginx restart.</p>
            <h3>Enable and start</h3>
            <p>Add/update these settings in your existing <code>.env</code>, preserving its secrets:</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>APP_PORT=18473
APP_URL=https://localhost:18473
COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml</code></pre></div>
            <p>The colon separator is for WSL/Linux/macOS. Native Windows Compose uses <code>COMPOSE_PATH_SEPARATOR=;</code> and a semicolon-separated <code>COMPOSE_FILE</code>, or explicit <code>-f</code> arguments. Remove the obsolete <code>APP_HTTPS_PORT</code>; the earlier 18474 port is no longer published. APP_URL chooses the origin for copied mock curls; either scheme works, and custom APP_PORT / APP_URL ports must agree.</p>
            <div class="code-block-wrap docs-example template-preview"><pre><code>docker compose config --quiet
docker compose up -d --build --wait
curl -fsS http://localhost:18473/up
curl -fsS https://localhost:18473/up</code></pre></div>
            <p>If the HTTPS client does not trust the issuing CA, use <code>curl --cacert /path/to/rootCA.pem https://localhost:18473/up</code>. Locate the CA with <code>mkcert -CAROOT</code> on the generating OS/user. WSL, Windows, containers and other machines have separate trust stores. Do not force Secure-only session cookies if HTTP dashboard login is also required.</p>
            <details class="normalized-details" data-disclosure>
                <summary><x-disclosure-chevron /> Existing installation and HTTP-only fallback</summary>
                <p>Certificate-only changes need no image rebuild. Use <code>make nginx-reload</code> with the app running to validate and recreate Nginx. With app running, validate the mount/configuration and recreate only Nginx:</p>
                <div class="code-block-wrap docs-example template-preview"><pre><code>docker compose run --rm --no-deps nginx sh -c 'ls -l /etc/nginx/certs &amp;&amp; nginx -t'
docker compose up -d --force-recreate nginx</code></pre></div>
                <p>If APP_URL or application environment settings changed, recreate app and callback-worker first, then Nginx. To return to HTTP only, comment/remove COMPOSE_FILE, use APP_URL=http://localhost:18473, and recreate the affected services with the base configuration. See the repository guide <code>docs/HTTPS.md</code> for full setup and diagnostics. This certificate setup is for localhost development; externally exposed hosts need certificates for their real DNS names.</p>
            </details>
        </section>

        <section id="connection-troubleshooting" class="card docs-card docs-wide">
            <h2>Connection and certificate troubleshooting</h2>
            <dl class="definition-list">
                <div><dt>mkcert not found</dt><dd>Install it in the OS/shell where you run it. Installing in WSL does not install a Windows executable.</dd></div>
                <div><dt>Failed to save certificate</dt><dd>Create docker/nginx/certs from the repository root, then generate both files. Backslashes in Bash can create dockernginxcerts instead; use mkdir -p docker/nginx/certs. Remove an accidental empty directory with rmdir dockernginxcerts.</dd></div>
                <div><dt>Nginx restarting: cannot load certificate</dt><dd>Missing localhost.pem or localhost-key.pem prevents Nginx from starting, so both protocols fail. Inspect local and mounted files, generate missing certificates, validate with nginx -t, and recreate Nginx. No app rebuild is needed.</dd></div>
                <div><dt>curl error 7: Failed to connect</dt><dd>Nothing is reachable at that address/port. Inspect Nginx status, published port and logs. This occurs before TLS or mock matching.</dd></div>
                <div><dt>NET::ERR_CERT_AUTHORITY_INVALID / curl error 60</dt><dd>TLS is reachable but the client does not trust the issuing CA. Follow the Windows/WSL trust steps or supply --cacert. Do not disable verification for normal use.</dd></div>
                <div><dt>Read-only default.conf startup message</dt><dd>The image's IPv6 helper cannot modify the deliberately read-only config. That informational message alone is not a fatal error; inspect the following error rather than making the mount writable.</dd></div>
                <div><dt>No published port during restart</dt><dd>Resolve the Nginx startup error first, then check APP_PORT and that COMPOSE_FILE includes both the base and HTTPS configuration.</dd></div>
            </dl>
            <div class="code-block-wrap docs-example template-preview"><pre><code>docker compose ps -a
docker compose port nginx 80
docker compose logs --tail=80 nginx
ls -l docker/nginx/certs
docker compose run --rm --no-deps nginx sh -c 'ls -l /etc/nginx/certs &amp;&amp; nginx -t'
grep -E '^(APP_PORT|APP_URL|COMPOSE_FILE|COMPOSE_PATH_SEPARATOR)=' .env
curl -v http://127.0.0.1:18473/up</code></pre></div>
            <p>Share status/log output and these non-secret settings when asking for help, never the full .env or private keys. For a running dual-protocol service, <code>MOCKDECK_SMOKE_ORIGIN=http://localhost:18473 python3 scripts/smoke_http_https.py</code> verifies both protocols; add <code>MOCKDECK_SMOKE_CA=/path/to/rootCA.pem</code> if Python needs explicit CA trust.</p>
        </section>

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
            <p>An endpoint with zero saved responses shows a <strong>No response</strong> badge and an <strong>Add response</strong> link in the registry. The link opens the Response tab, where the note explains the diagnostic 500 until a response is saved. The note and tab cue clear after saving, including for imported Postman requests with no saved examples. No placeholder response is created.</p>
            <p>Disabled endpoints never participate in matching. When multiple enabled endpoints could match, the highest priority wins. If priorities are equal, the more specific signature wins.</p>
            <h3>Saving endpoint and response drafts</h3>
            <p>On an existing endpoint, <strong>Save changes</strong> commits response/selection/callback drafts first, in their own transaction, then validates and saves the endpoint. These saves are not atomic across both components. If the endpoint is then rejected, for example because another tab created a duplicate signature, the response changes remain saved while the endpoint stays unsaved. Browser validation, such as an out-of-range priority, normally prevents submission before those saves begin.</p>
            <p>In-app navigation away from a dirty editor opens the shared <strong>Discard unsaved changes?</strong> confirmation. Reload and tab close use the browser's own leave prompt; its wording is browser-controlled and the browser may not show it. Unsaved edits are held in memory only: there is no autosave or draft recovery. Accepting a leave prompt discards unsaved edits; saved changes remain.</p>
            <p>After session expiry, the next page load or Livewire action redirects to the login page in the observed runtime checks; no 419 was observed. The browser leave prompt may appear during that navigation. Accepting it loses unsaved edits, while saved changes remain. Cancelling a prompt can retain the old page temporarily; it is not draft recovery.</p>
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
            <p>For endpoints created in the editor, <strong>Ignore cookies</strong> starts off, including when the saved cURL has no <code>Cookie</code> header. An incoming request carrying <code>Cookie</code> will not match a saved request without it unless cookies are excluded by the matching policy.</p>
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
            <p>Choose <strong>Weighted</strong>, <strong>Sequence</strong>, or <strong>Rule-based</strong> above the configured response list, then select <strong>Save selection</strong>. Sequence uses the existing row drag handles (or up/down controls); choose Repeat last, Loop, or Not found on exhaustion. The next-call readout and confirmed Reset sequence are scoped to the active environment.</p>
            <p>Rule-based selection combines each response's Conditions with AND, tries responses in ascending condition priority, and requires exactly one Default / fallback. Drag responses to change global rule priority. The saved fallback cannot be deleted until a replacement has been selected and saved. Selection preview shows the next response or ordered conditions without advancing runtime state.</p>
        </section>

        <section id="async-callbacks" class="card docs-card docs-wide">
            <h2>Asynchronous callbacks</h2>
            <p>Open a response's <strong>Callback</strong> disclosure, enable it, and enter an HTTP(S) URL, method, header JSON, and a JSON body template. Save the response. The separate callback worker runs after the mock response is sent, so network failures and configured delay never hold up the caller.</p>
            <p>Use <code>@verbatim{{env.KEY}}@endverbatim</code> for Environment values and <code>$request.method</code>, <code>$request.id</code>, <code>$request.body</code>, or <code>$request.json.field</code> for the triggering request. The callback body reuses the response Faker template renderer. Choose Builder for field-based JSON or JSON for context tokens and advanced directives; switching views does not discard the body. Maximum delay is 30 seconds; Max attempts includes the first try (1–5), non-2xx responses retry after the configured backoff, and each attempt has a bounded timeout.</p>
            <p>The Environment ID and request context are bound when the request is served. When the callback worker handles the job, it reloads the saved callback configuration and reads that Environment's current values. It resolves URL, headers and body once, before the initial delay and retries; retries reuse those resolved values. Disabling a callback does not cancel delivery already queued, including when disabled through the shared save service. If the source response is deleted before the worker loads it, the job returns without delivery.</p>
            <h3>Signature verification</h3>
            <p>When <strong>Sign requests</strong> is enabled, MockDeck computes lowercase hex HMAC-SHA256 over the <strong>exact raw resolved body bytes</strong> using the write-only signing secret, then places the digest in the configured header (default <code>X-MockDeck-Signature</code>). Verify it against raw bytes before parsing or re-encoding JSON. With signing disabled, the signature header is omitted entirely.</p>
            <p>Save callback changes, then choose <strong>Test saved callback</strong> even without a previous request. Testing is blocked while callback settings are unsaved; the redacted saved target is shown beside the action. The inline result shows status, HTTP code, and duration. Open <a href="{{ route('dashboard.logs.index', ['type' => 'callbacks']) }}" wire:navigate>Logs → Callbacks</a> to filter attempts and select <strong>Resend</strong>, which creates a fresh attempt using the stored resolved target, method, headers and body, signing with the current settings rather than re-resolving Environment values. The original attempt and primary response stay unchanged.</p>
            <p>Resolved fields may contain secret environment values, so callback API reads and exports do not reveal them. Callback targets are only checked for well-formed HTTP(S) URLs; localhost/internal targets are intentionally allowed for testing. Do not expose this capability to untrusted users.</p>
            <p>The callback-attempt API's <code>target</code> is the response's current saved callback URL, possibly containing Environment tokens, or <code>[response deleted]</code>. The dashboard's <strong>Recorded target</strong> instead shows the attempt's stored destination through the existing redactor. The API never returns resolved headers or body; its <code>target</code> is not historical resolved-request evidence.</p>
        </section>

        <section id="response-templating" class="card docs-card docs-wide">
            <h2>Response templating</h2>
            <p>Choose <strong>Body → Template</strong> to generate response JSON with Faker data. Templating is opt-in per response; existing static responses are unchanged. Preview and live serving use the same server-side parser and renderer.</p>
            <p>The backend maps supported Faker.js-shaped method names to FakerPHP 1.24. No Faker runtime or template evaluator is shipped to the browser. Successful templates default to <code>application/json</code> when the response does not define Content-Type.</p>
            <p>In the JSON editor, use <code>$request.method</code>, <code>$request.url</code>, <code>$request.id</code>, <code>$request.body</code>, <code>$request.json.user.name</code>, and <code>@verbatim{{env.KEY}}@endverbatim</code>. Inline text such as <code>@verbatimHello {{$request.method}}@endverbatim</code> works too. Preview labels its synthetic request samples. Missing JSON paths resolve to null; bodies over 64 KiB omit body/JSON context and produce a visible warning in Logs. Request/environment tokens require the JSON editor in this wave.</p>
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
                <li>Choose a MockDeck JSON file and select Create only, Upsert, or Clone. Native format 1.5 includes selection mode, sequence order/exhaustion, fallback flags, response rules, field exclusions, path patterns, and primary response faults. Version 1.3 retains selection settings while defaulting new fields off. Versions 1–1.2 import as Weighted. Runtime call counters, sequence positions, and recent call digests are never exported or inherited by new imports/clones.</li>
                <li>Preview the complete plan; preview never writes to the database.</li>
                <li>Resolve errors and review warnings. Upsert replaces response pools only when explicitly enabled.</li>
                <li>Confirm to apply atomically. Version 1 files remain supported and missing template fields become static responses.</li>
                <li>Update-by-UUID preview shows how many endpoint/response pre-states will be versioned. After apply, Undo this import restores the complete recorded batch.</li>
            </ol>
        <p><strong>Postman v2.1:</strong> choose Postman in the Import panel. Preview flattened folders, variable exclusions and saved examples before confirmation. Whole path variables become parameters; body variables stay literal. Scripts are never executed. Create only skips matching correlation keys; Update matching replaces the saved response pool with an undoable revision batch. Renaming or moving items breaks correlation. Companion Environment files and Postman export are not included.</p>
        </section>

        <section id="wave-two" class="card docs-card">
            <h2>Field exclusions, path parameters and faults</h2>
            <p>In Matching, toggle Exclude beside individual query parameters or headers. Excluded values remain visible for review; coarse header policies disable redundant controls. In Request, enable Use path parameters, then convert and name individual segment chips.</p>
            <p>A path such as <code>/users/{id}</code> matches one segment. Query, retained headers and body still match exactly. Literal paths win at equal priority. V6 applies only to endpoints using exclusions or patterns; existing signatures remain unchanged.</p>
            <p>Response and callback JSON templates can use <code>$request.path.id</code>. Preview uses sample captures. These tokens stay in the JSON editor.</p>
            <p>In Response, expand Fault injection for probability, bounded delay, malformed JSON/XML, byte truncation or Timeout (long delay). Timeout is an approximation: proxy and worker limits still apply. TCP connection reset is not offered. Faulted calls remain counted and are labelled in Logs.</p>
        </section>
        <section id="verification" class="card docs-card">
            <h2>CI verification</h2>
            <p>Generate an instance token with <code>php artisan mockdeck:api-token</code>; it is shown once and stored as a hash. Add <code>--rotate</code> to replace the previous token. Dashboard cookies do not authenticate the verification API.</p>
            <p>Use <code>/api/v1/verify/endpoints/{uuid}/calls</code>, <code>/assert</code>, <code>/reset</code>, or <code>/api/v1/verify/reset-all</code> with an explicit environment name. Assertions use Equals, At least or At most and optional response-rule-shaped request predicates.</p>
            <p>Lifetime counts are exact. Conditional assertions use at most 20 bounded call digests and report indeterminate when retained data cannot prove the result. Reset before a focused CI scenario. Runtime resets do not change configuration. Full curl examples and response shapes are in <code>docs/VERIFICATION_API.md</code>.</p>
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
                <li><strong>Copy request curl</strong> copies the reconstructed command, containing method and URL only, through the shared clipboard handler.</li>
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

        <section id="isolated-test-runs" class="card docs-card docs-wide">
            <h2>MCP tools and isolated test runs</h2>
            <p>Connect an agent to /mcp with a separately issued MCP bearer. Four explicit permissions control 18 tools for run lifecycle, mock configuration, match diagnosis and verification. Dashboard cookies and verification tokens cannot authorize these tools.</p>
            <ul>
                <li>Header routing: send <code>X-MockDeck-Test-Run: &lt;public UUID&gt;</code> with the original mock URL.</li>
                <li>Base-URL routing: use <code>http://localhost:18473/_mockdeck/run/&lt;public UUID&gt;</code> as the API base, then append the original path/query. HTTPS works with the existing TLS setup.</li>
                <li>Each run matches only its own mocks. A miss never falls back to ordinary mocks or another run; ordinary requests never select run mocks.</li>
                <li>The run uses its bound environment for templates and callbacks without changing the dashboard's active environment. Counters and sequences are isolated too.</li>
                <li>Unknown, finished or revoked-owner runs return 404. Conflicting header/prefix run IDs return 400. Keep public run UUIDs private: they grant routing access.</li>
                <li><code>/_mockdeck</code> and <code>/mcp</code> are reserved. Run mocks/state are excluded from normal exports; MCP writes have a read-only run History view using the existing timeline. Verification API tokens remain ordinary-only.</li>
            </ul>
            <p>Use start_test_run → create_mock → get_calls / assert_calls → finish_test_run. The read-only preview_template tool generates labeled samples without saving them. For Postman fixtures, use preview_postman_import (read) then apply_postman_import (write, with an idempotency key); endpoints belong to the run, folder Collections remain shared, and scripts never execute. MCP mocks also support Sequence and Rule-based response pools; peek_next_response shows the next Sequence response without consuming it. Exact counters are separate from the 20-call evidence window; incomplete filtered assertions return INCONCLUSIVE_EVIDENCE. The credential lifecycle, schemas and tested local client are documented in <code>docs/MCP.md</code>. No external service or new dashboard management UI is required.</p>
        </section>

        <section id="shortcuts" class="card docs-card">
            <h2>Keyboard shortcuts</h2>
            <dl class="shortcut-list">
                <div><dt><kbd>N</kbd></dt><dd>Create a new endpoint</dd></div>
                <div><dt><kbd>/</kbd></dt><dd>Focus endpoint or log search</dd></div>
                <div><dt><kbd>?</kbd></dt><dd>Open shortcut help</dd></div>
                <div><dt><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>Enter</kbd></dt><dd>Save the endpoint form</dd></div>
            </dl>
            <p><strong>Known gaps:</strong> the single-character shortcuts <kbd>N</kbd>, <kbd>/</kbd> and <kbd>?</kbd> have no disable or remap option. Tooltip content cannot be hovered onto, and Escape does not dismiss a tooltip opened by hover only.</p>
        </section>
        <section id="licensing" class="card docs-card docs-wide">
            <h2>License and third-party notices</h2>
            <p>MockDeck's application code is source-available under the PolyForm Strict License 1.0.0, copyright © 2026 <a href="https://github.com/ru5iru">Rusiru Rathmina</a>. It permits the noncommercial purposes described in the license and does not grant permission to modify or redistribute the application. Commercial use requires separate permission from the copyright holder.</p>
            <p>Third-party packages and fonts retain their own licenses. Previously released MIT versions retain their MIT rights. Read <code>LICENSE</code> and <code>docs/LICENSING.md</code> in the distribution for the full terms, historical release boundary and third-party notices.</p>
        </section>
    </div>
</x-layouts.dashboard>
