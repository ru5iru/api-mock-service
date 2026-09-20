# Change log

## Native configuration transfer — 2026-09-18

### `feat(config): add stable portable uuids`

- adds immutable UUIDv7 identities to endpoints and responses;
- backfills existing records in ordered chunks;
- covers generated and immutable identifiers.

Commit: `a96c310`

### `feat(config): add portable import and export workflows`

- exports versioned deterministic JSON for all or selected endpoints;
- redacts configured request secrets by default and disables affected exports;
- validates file, document, field, semantic, and derived request constraints;
- previews UUID, exact signature, response identity, and overlap conflicts;
- applies create-only, upsert, and clone imports atomically behind an expiring digest-bound token;
- exposes dashboard, protected HTTP, and Artisan workflows.

Commit: `e6cf926`

### `test(config): cover portable configuration workflows`

- covers deterministic redaction, create/upsert idempotency, conflict blocking, overlap warnings, acknowledgement, HTTP flows, CLI dry-runs, and UUIDs.

Commit: `8ad5254`

### `docs(config): document portable configuration operations`

- documents UI, HTTP, CLI, security, limits, conflict policy, recovery, and remaining Phase 1 hardening.

## Fixed delivery — 2026-09-18

### `fix(matching): resolve endpoint conflicts deterministically`

- ranks matches by explicit priority, signature specificity, and stable ID;
- ignores disabled endpoints;
- adds endpoint enabled/priority controls and signature-version storage;
- prevents exact duplicate signatures in the dashboard;
- defaults new endpoints to excluding authentication from matching;
- adds conflict, priority, disabled-state, and duplicate regression tests.

Commit: `dd01131`

### `fix(security): protect dashboard and deployment defaults`

- adds rate-limited dashboard session login and sign-out;
- persists custom access middleware across Livewire requests;
- validates absolute HTTP/HTTPS URLs and rejects embedded URL credentials;
- returns diagnostic `400` responses for normalization failures;
- refuses placeholder production application, database, and dashboard secrets;
- adds authentication and URL-safety tests.

Commit: `8525661`

### `fix(observability): keep request logging non-fatal`

- prevents logging destination failures from breaking mock responses;
- adds validated/generated request IDs to responses and events;
- logs the effective absolute matching URL;
- reads bounded events across rotated files and skips malformed lines;
- adds log-failure, request-ID, and rotation regression coverage.

Commit: `092f853`

### `feat(ui): add registry and log filters`

- filters endpoints by text, method, and runtime state;
- enables/disables endpoints directly from the registry;
- filters request events by method, match outcome, and status family;
- improves responsive toolbar layouts and status presentation;
- adds endpoint-filter and state-toggle coverage.

Commit: `307ea1e`

### `ci: add repeatable validation workflow`

- adds focused and full Docker validation targets to the Makefile;
- adds Composer, Pint, and PHPUnit checks for pushes and pull requests.

Commit: `fd0c372`

### `docs: add import export implementation roadmap`

- updates secure setup, matching, operations, and validation guidance;
- documents the revised architecture and release checks;
- provides a researched, import/export-first feature implementation plan.

### `feat(matching): make request signatures origin independent`

- removes scheme, host, and port from canonical signatures while retaining absolute-URL validation;
- excludes unstable transport headers and request IDs from saved/incoming signatures;
- removes all reliance on an original-URL header or related environment setting;
- rebuilds stored hashes and marks the new canonical contract as signature version 2;
- adds origin, transport-header, migration-path, and backward-compatibility coverage.

Commit: `ec154ab`

### `feat(ui): add copyable mock curl commands`

- generates invocation curls using `APP_URL` while preserving request details;
- adds endpoint-list copy controls with secure clipboard and HTTP fallback paths;
- keeps the registry usable when a malformed legacy curl cannot be converted;
- adds curl generation, shell quoting, base-URL validation, and dashboard coverage.

Commit: `b6ab429`
