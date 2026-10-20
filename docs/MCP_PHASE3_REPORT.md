# MCP Phase 3 delivery and acceptance report

Implemented on development baseline `e575b3d`, after confirming Phase 1 shared services/credentials and Phase 2 run ownership/routing had landed. No push or deployment was performed.

## Implementation

The server adopts official **`laravel/mcp` v1.0.2**, the only added/changed Composer package, for Laravel 13 / PHP 8.3 compatibility and Streamable HTTP. JSON-RPC framing, initialization, discovery and structured tool responses use the maintained SDK. `/mcp` requires Phase 1’s independently revocable bearer, applies both named throttles and checks browser Origin and a 1 MiB input bound. Resources/Prompts are not advertised; a persistent SSE/session channel is unnecessary, so GET/DELETE return 405 after authentication. The SDK also supports its 2026-07-28 stateless discovery protocol.

`McpDispatcher` enforces the fixed permissions and owner scope, validates the public schema, serializes run mutations and audits each accepted tool call (including cache hits/errors). SDK-rejected tool envelopes are audited separately without argument retention. `McpMockConfiguration` merges partial input into shared typed DTOs; endpoint/response writes, signature parsing, template/Faker validation, matching, verification predicates, reset and lifecycle operations call the existing services. PostgreSQL uses row locks; SQLite reserves its writer before reads to avoid deferred transaction upgrade races.

Create is atomic across endpoint/responses/revision/idempotency cache. Keys are scoped by credential/run/tool and hash the full input with object-key ordering normalized; cached redacted results expire after 24 hours. Same-run canonical collisions return CONFLICT with the existing mock ID/name. Endpoint revision numbers provide the only configuration-version counter; stale updates cannot overwrite. Response-only changes advance it; no-op patches preserve it.

Run changes explicitly opt into normal `RevisionManager` history with redacted snapshots. Reset and delete-cleanup also record endpoint revisions, as Phase 3 requires. The existing History timeline/diff component has a locked read-only run scope and remains available after mock deletion. This supersedes Phase 2’s blanket exclusion of agent configuration revisions; runtime counters are still excluded from snapshots and all run data remains excluded from native format **1.5**. Ordinary dashboard history and serving behavior remain unchanged.

## Full P0 inventory

| Tool | Permission | Behavior |
|---|---|---|
| start_test_run | write | Owned run, fixed Environment, both routing instructions |
| list_test_runs | read | Owned active/finished runs, paginated recovery |
| create_mock | write | Atomic request plus responses, Weighted defaults, idempotency and duplicate conflicts |
| list_mocks | read | Owned-run filters/pagination |
| get_mock | read | Redacted configuration, responses, revision/version and History link |
| validate_mock | read | Existing validators, structured errors/warnings, no persistence |
| update_mock | write | Endpoint patch and individual response add/update/remove, expected version |
| get_mock_url | read | Both invocation forms and POSIX curls |
| explain_match | read | Real matcher, raw curl or retained unmatched ID, field differences/exclusions, no serving effects |
| get_unmatched_requests | verify | Stable IDs in encrypted 20-entry / 24-hour scoped window |
| get_calls | verify | Exact counters plus explicit retained-evidence completeness |
| assert_calls | verify | Existing grammar/comparators; definite failure or explicit inconclusive evidence |
| reset_test_run | cleanup | Run-only runtime reset, configuration preserved |
| finish_test_run | cleanup | Keep/delete own mocks, persisted idempotent final summary |

No P0 tool was deferred. Clarifications/bounds are explicit:

- Reads are **own-run-only**, even with read permission; ordinary dashboard mocks are not accessible through P0 MCP tools.
- All four grants are independent. Finish requires cleanup for either choice, matching Phase 1’s published permission contract.
- Results use Phase 1 redaction and a 4096-byte per-string bound; responses are capped at 50. `get_mock` is configuration plus bounded response summaries. Agents patch only intentional fields, preserving omitted text; they must never echo redactions/truncation back into a write.
- Unmatched evidence is a separate encrypted ring so optional dashboard log retention cannot control diagnosis. Evidence is bounded to 64 KiB body / 64 headers / 4096 bytes per header; truncation prevents claims of exact log-based diagnosis. Field differences omit values. Candidate-window completeness is explicit.
- Filtered verification uses the existing evaluator’s count bounds. A provable result is definite; otherwise INCONCLUSIVE_EVIDENCE includes unretained/undecidable counts. Unfiltered counters remain exact.
- Cached create replay after finish returns the original result and never recreates a deleted mock or reopens routing. A new create in a finished run fails.
- Audit arguments are never stored; the audit table keeps allowlisted actor/tool/target/run/outcome/correlation metadata. Actual mock configurations necessarily retain caller-supplied values for matching/serving, while tool results, retry caches and scoped history redact secret-shaped data and known secret Environment values.

Full input/output schemas and annotations: [MCP_TOOLS.json](MCP_TOOLS.json). Lifecycle, examples, addresses and troubleshooting: [MCP.md](MCP.md).

## Tested client and worked workflow

Tested: official **Laravel MCP PHP WebClient v1.0.2**, `withToken`, protocol **2025-11-25**, real TCP HTTP and verified HTTPS. Certificate verification uses a locally generated explicit CA; it is never disabled in the client. A disposable PHP TLS terminator supplies test TLS. Modern 2026-07-28 discovery also passes. No universal editor/cloud-client/OAuth/stdio compatibility is claimed.

The runnable [mcp_example.php](../scripts/mcp_example.php) is executed by the integration test. It discovers 14 tools, starts a run, creates static GET `/demo`, then makes these two calls (IDs are generated at runtime):

```text
start_test_run(label="local MCP example") → RUN_UUID
create_mock(run_id=RUN_UUID, key="example-create", GET /demo, body={"ok":true}) → MOCK_UUID
GET /demo + X-MockDeck-Test-Run: RUN_UUID → 200 {"ok":true}
GET /_mockdeck/run/RUN_UUID/demo → 200 {"ok":true}
get_calls(RUN_UUID, MOCK_UUID) → total_match_count=2
assert_calls(..., expect_count=2, comparator="equals") → passed=true
finish_test_run(RUN_UUID, cleanup="delete_mocks") → finished
```

The finally block cleans up even if an intermediate assertion fails. Host/container origins and trusted-certificate setup are documented in MCP.md; returned URLs use APP_URL and may require origin replacement from another container.

## Acceptance mapping

| Criterion | Result and evidence |
|---|---|
| Local authenticated client discovers/calls tools without external runtime services | PASS for actual local SDK workflow; strict network-isolation check separately BLOCKED locally below |
| Missing/revoked credentials rejected | PASS — McpTransportTest / McpCredentialTest |
| Valid Operator session cookie alone cannot authorize MCP | PASS — dashboard access confirmed, actual /mcp still 401 |
| Run creation and atomic static mock, callable URL/curl in both routing forms | PASS — McpWorkflowTest and actual SDK HTTP/TLS integration |
| Same-key identical create does not duplicate | PASS — sequential replay and four simultaneous official clients |
| Same key/different full input gives IDEMPOTENCY_CONFLICT | PASS — McpIdempotencyTest / McpWorkflowTest |
| Invalid configurations leave no partial endpoint/response/cache/revision writes | PASS — second-response CRLF header fails shared domain validation after the first response is built; all writes roll back; workflow checks scoped totals |
| Concurrent updates cannot silently overwrite | PASS on SQLite — two simultaneous SDK edits yield one success/one CONFLICT; PostgreSQL variant wired in CI, not run locally |
| Identical shapes in two runs and ordinary scope coexist under concurrent load | PASS — ConcurrentRunRoutingTest: 8 workers, 24 barrier-synchronized clients, independent counters and sequence positions |
| Ordinary dashboard requests/mocks remain unaffected | PASS — Phase 1 characterization and full serving/regression suite, Phase 2 isolation tests |
| Useful redacted diagnosis without counters/selection/callback effects | PASS — real matcher invoked read-only; McpReadToolsTest and matching/template regression |
| Unfiltered exact assertions pass/fail | PASS — workflow plus existing verification comparator coverage |
| Incomplete filtered evidence explicitly inconclusive | PASS — 21 calls in 20-entry ring, INCONCLUSIVE_EVIDENCE and one unretained call |
| Run reset preserves configuration and scope | PASS — byte-identical endpoint attributes, run-only SQL scope; existing verification and isolation tests |
| Finish never deletes another run or ordinary mock and is safe twice | PASS — McpWorkflowTest / McpSecurityBoundaryTest, original summary returned |
| Secret-shaped values absent from tool results and invocation audit | PASS — result/cache/history/audit tests cover auth, query keys, body passwords, Set-Cookie and secret Environment values |
| HTTP and HTTPS with verified trust | PASS for actual MCP client and mock calls through test TLS; production same-port Nginx check BLOCKED locally, retained in CI |
| Existing imports/exports, revisions, selection, logs, callbacks | PASS — full PHPUnit suite; native format remains 1.5 |
| Strict network-isolated initialization/normal operation | BLOCKED locally — no Docker and network namespace creation denied; `make mcp-offline-test` runs the real client suite with `--network none` in CI |
| Same-run signature collision clearly names existing mock | PASS — CONFLICT includes the existing UUID/name instead of creating a duplicate |
| Log-entry explanation equivalent to raw curl | PASS — same selected candidate and per-field differences; no counter changes |
| Template/Faker/path/exclusion and response add/update/remove compose | PASS — McpWorkflowTest renders path ID, bound Environment and seeded UUID, then patches pool while preserving omitted fields |
| Schema/permission/annotation reference cannot drift silently | PASS — McpSchemaReferenceTest compares all 14 tool names and checks generated JSON reference |

## Validation actually run

- **337 PHPUnit tests passed, 2,687 assertions**, PHP 8.4.12 CLI in this workspace. Project PHP 8.3 remains the CI/container target.
- **52 frontend tests passed**, **11 setup tests passed**.
- Design tokens: PASS, including light/dark contrast validation.
- Composer metadata `validate --strict`: PASS. Pint full-project check: PASS. Git whitespace check: PASS.
- Real official MCP client HTTP/trusted-TLS workflow and runnable example: PASS.
- Mutation contention: PASS; five consecutive parallel runs passed after fixing the SQLite writer-reservation race.
- `make validate`: **BLOCKED** at `docker compose config --quiet`, Docker executable absent. Native constituent checks above were run independently.
- `make mcp-offline-test`, PostgreSQL-specific concurrency and production same-port Nginx TLS: **NOT RUN locally**, Docker unavailable. CI is explicitly wired for each.
- Playwright layout smoke in both themes: **BLOCKED before browser launch**. Chromium is absent; its install download failed with a truncated/non-ZIP archive. No layout screenshot or browser pass is claimed. Existing CI browser checks remain enabled.
- Additional Composer audit reports two **pre-existing** `league/commonmark` advisories; this phase did not change that package. The only dependency delta is `laravel/mcp` v1.0.2. This is not represented as a clean dependency-audit result.

## Applying the changed-file package

The archive contains only files added/changed from baseline `e575b3d`, at repository-relative paths, plus its changed-file inventory. It contains no `.env`, credentials, certificate keys, vendor tree or database. Back up the working checkout, extract into its root, then run:

```bash
make up
make health
make validate
make mcp-offline-test
```

Normal startup installs Composer dependencies and applies the new MCP runtime migration. Do not reset the database or replace existing Environment/configuration state. Reuse or issue explicit MCP credentials through the existing lifecycle CLI, then follow MCP.md’s client example. Native export format stays 1.5.
