<?php

namespace Tests\Feature;

use App\Models\McpCredential;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Models\TestRun;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\Mcp\McpOutput;
use App\Services\TestRuns\RunRouting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class McpPostmanImportTest extends TestCase
{
    use RefreshDatabase;

    private McpCredential $owner;

    private string $token;

    private array $run;

    protected function setUp(): void
    {
        parent::setUp();
        $issued = app(McpCredentialService::class)->generate('Postman agent', ['read', 'write', 'verify', 'cleanup']);
        $this->owner = $issued->credential;
        $this->token = $issued->reveal();
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'Postman'])['data']['run_id']];
    }

    public function test_preview_has_dashboard_plan_variables_credentials_and_inert_script_warnings_without_any_writes(): void
    {
        $args = [...$this->run, 'collection' => $this->collection(), 'environment' => json_encode(['values' => [['key' => 'cartId', 'value' => 'companion']]])];
        $mapper = app(PostmanMapper::class)->withVariableOptions(['environment_file_values' => [['key' => 'cartId', 'value' => 'companion']]]);
        $expected = app(MappedImportService::class)->preview($mapper, $args['collection'], ImportMode::CreateOnly)->toArray();
        $expected['token'] = $expected['digest'] = '';
        $this->owner->update(['can_write' => false]);
        $this->travel(60)->seconds();
        $before = $this->snapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->postJson('/mcp', $this->envelope('preview_postman_import', $args), ['Authorization' => 'Bearer '.$this->token])->assertOk();
        $result = $response->json('result.structuredContent');
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(app(McpOutput::class)->safe($expected, TestRun::where('public_id', $this->run['run_id'])->first()), $result['data']);
        self::assertSame('create', $result['data']['items'][0]['action']);
        self::assertSame('Auth / Login', $result['data']['items'][0]['collection']);
        self::assertStringContainsString('companion', json_encode($result['data']['metadata']['variable_candidates']));
        $warnings = implode(' ', $result['data']['items'][0]['warnings']);
        self::assertStringContainsString('Pre-request script present', $warnings);
        self::assertStringContainsString('Test script present', $warnings);
        self::assertTrue($result['data']['metadata']['contains_secrets']);
        self::assertSame(['{{stg_token}}'], $result['data']['items'][0]['variable_fields'][0]['tokens']);
        self::assertStringNotContainsString('credential-value', json_encode($result));
        self::assertStringNotContainsString('pm.environment.set', json_encode($result));
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $writes);
        self::assertFalse($this->owner->fresh()->can_write);
    }

    public function test_preview_correlates_only_owned_run_and_resolve_uses_bound_environment_without_creating_variables(): void
    {
        $collection = $this->collection();
        $document = app(PostmanMapper::class)->map($collection);
        MockEndpoint::factory()->create(['external_source' => ['type' => 'postman', 'correlation_key' => $document['items'][0]['correlation_key']]]);
        $run = TestRun::where('public_id', $this->run['run_id'])->first();
        $run->environment->variables()->create(['key' => 'stg_token', 'value' => 'bound', 'is_secret' => true]);
        $run->environment->variables()->create(['key' => 'cartId', 'value' => 'run-cart', 'is_secret' => false]);
        $before = $this->snapshot();
        $result = $this->callTool('preview_postman_import', [...$this->run, 'collection' => $collection, 'variable_mode' => 'resolve']);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('create', $result['data']['items'][0]['action']);
        self::assertSame([], $result['data']['errors']);
        self::assertStringNotContainsString('bound', json_encode($result));
        self::assertSame($before, $this->snapshot());
    }

    public function test_invalid_or_oversized_files_fail_safely_and_existing_transport_bound_is_preserved(): void
    {
        $before = $this->snapshot();
        foreach (['{broken', '{"info":{"schema":"v1"},"item":[]}', str_repeat('é', 1048577)] as $collection) {
            $result = $this->callTool('preview_postman_import', [...$this->run, 'collection' => $collection]);
            self::assertSame('VALIDATION_ERROR', $result['error']['code'], json_encode($result));
        }
        foreach (['{broken', '{"values":"invalid"}', str_repeat('x', 2097153)] as $environment) {
            self::assertSame('VALIDATION_ERROR', $this->callTool('preview_postman_import', [...$this->run, 'collection' => $this->collection(), 'environment' => $environment])['error']['code']);
        }
        self::assertStringContainsString('v2.1', json_encode($this->callTool('preview_postman_import', [...$this->run, 'collection' => '{}'])['error']['details']));
        // Valid JSON-RPC exceeding the old 1 MiB envelope is allowed only for imports.
        $collection = $this->collection(['description' => str_repeat('x', 1100000)]);
        $this->postJson('/mcp', $this->envelope('preview_postman_import', [...$this->run, 'collection' => $collection]), ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()->assertJsonPath('result.structuredContent.ok', true);
        $this->postJson('/mcp', $this->envelope('preview_template', [...$this->run, 'template' => str_repeat('x', 1100000)]), ['Authorization' => 'Bearer '.$this->token])->assertStatus(413);
        self::assertSame($before, $this->snapshot());
    }

    public function test_apply_matches_preview_sets_ownership_on_first_insert_and_routes_both_forms_without_leaks(): void
    {
        $collection = $this->routableCollection();
        $dashboard = app(MappedImportService::class)->preview(app(PostmanMapper::class), $collection, ImportMode::CreateOnly);
        app(MappedImportService::class)->apply($dashboard->token, $dashboard->digest);
        $ordinary = json_encode(MockEndpoint::query()->with('responses')->get()->toArray());
        $args = [...$this->run, 'collection' => $collection];
        $preview = $this->callTool('preview_postman_import', $args);
        self::assertSame('create', $preview['data']['items'][0]['action']);
        $run = TestRun::where('public_id', $this->run['run_id'])->first();
        $inserts = [];
        MockEndpoint::creating(function (MockEndpoint $endpoint) use (&$inserts): void {
            $inserts[] = $endpoint->test_run_id;
        });
        $result = $this->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'apply']);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame([$run->id], $inserts);
        self::assertSame($preview['data']['items'], $result['data']['plan']['items']);
        self::assertSame(1, $result['data']['summary']['endpoints_created']);
        $item = $result['data']['items'][0];
        self::assertSame('create', $item['action']);
        self::assertSame($run->id, $run->endpoints()->sole()->test_run_id);
        self::assertSame(1, $run->endpoints()->sole()->responses()->count());
        $header = $item['invocation']['header'];
        $prefix = $item['invocation']['path_prefix'];
        $this->get(parse_url($header['url'], PHP_URL_PATH), $header['headers'])->assertOk()->assertJsonPath('imported', true);
        $this->get(parse_url($prefix['url'], PHP_URL_PATH))->assertOk()->assertJsonPath('imported', true);
        self::assertSame(2, $this->callTool('get_calls', [...$this->run, 'mock_id' => $item['mock_id']])['data']['total_match_count']);
        self::assertSame($ordinary, json_encode(MockEndpoint::query()->with('responses')->get()->toArray()));
        $otherRun = ['run_id' => $this->callTool('start_test_run', ['label' => 'other'])['data']['run_id']];
        self::assertSame(0, $this->callTool('list_mocks', $otherRun)['data']['total']);
        self::assertSame('NOT_FOUND', $this->callTool('get_mock', [...$otherRun, 'mock_id' => $item['mock_id']])['error']['code']);
        $other = app(McpCredentialService::class)->generate('other credential', ['read', 'write'])->credential;
        self::assertSame('RUN_NOT_FOUND', app(McpDispatcher::class)->call('list_mocks', $this->run, $other)['error']['code']);
        $foreignRun = app(McpDispatcher::class)->call('start_test_run', ['label' => 'foreign'], $other)['data']['run_id'];
        self::assertSame(0, app(McpDispatcher::class)->call('list_mocks', ['run_id' => $foreignRun], $other)['data']['total']);
        $this->get('/cart', [RunRouting::HEADER => $otherRun['run_id']])->assertNotFound();
        self::assertStringNotContainsString($item['mock_id'], app(ConfigExporter::class)->export()->toJson());
        self::assertSame($run->id, Revision::where('entity_id', $run->endpoints()->sole()->id)->sole()->test_run_id);
        self::assertSame($result['data']['summary']['import_batch_id'], Revision::where('test_run_id', $run->id)->sole()->import_batch_id);
    }

    public function test_apply_retries_full_input_cache_conflicts_and_finished_run_replays_match_create_mock(): void
    {
        $args = [...$this->run, 'collection' => $this->routableCollection(), 'idempotency_key' => 'retry'];
        $first = $this->callTool('apply_postman_import', $args);
        self::assertTrue($first['ok'], json_encode($first));
        $snapshot = $this->configurationSnapshot();
        self::assertSame($first['data'], $this->callTool('apply_postman_import', $args)['data']);
        self::assertSame($snapshot, $this->configurationSnapshot());
        foreach ([['collection' => $this->routableCollection(201)], ['environment' => '{"values":[]}'], ['mode' => 'clone']] as $changed) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $this->callTool('apply_postman_import', [...$args, ...$changed])['error']['code']);
        }
        self::assertSame($snapshot, $this->configurationSnapshot());
        self::assertSame(1, DB::table('mcp_idempotency_keys')->count());
        $this->callTool('finish_test_run', [...$this->run, 'cleanup' => 'keep_mocks']);
        self::assertSame($first['data'], $this->callTool('apply_postman_import', $args)['data']);
        self::assertSame('RUN_FINISHED', $this->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'new'])['error']['code']);
    }

    public function test_create_only_update_clone_and_duplicate_actions_reuse_run_scoped_correlation_and_batches(): void
    {
        $args = [...$this->run, 'collection' => $this->routableCollection()];
        $created = $this->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'initial']);
        $mockId = $created['data']['items'][0]['mock_id'];
        self::assertSame('skip', $this->callTool('preview_postman_import', $args)['data']['items'][0]['action']);
        $skipped = $this->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'skip']);
        self::assertTrue($skipped['ok'], json_encode($skipped));
        self::assertSame([], $skipped['data']['items']);
        self::assertSame(0, $skipped['data']['summary']['endpoints_created']);
        $upsert = [...$this->run, 'collection' => $this->routableCollection(201), 'mode' => 'upsert'];
        self::assertSame('update', $this->callTool('preview_postman_import', $upsert)['data']['items'][0]['action']);
        $updated = $this->callTool('apply_postman_import', [...$upsert, 'idempotency_key' => 'update']);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame(1, $updated['data']['summary']['endpoints_updated']);
        self::assertSame($mockId, $updated['data']['items'][0]['mock_id']);
        $this->get('/cart', [RunRouting::HEADER => $this->run['run_id']])->assertStatus(201);
        $clone = $this->callTool('apply_postman_import', [...$args, 'mode' => 'clone', 'idempotency_key' => 'clone']);
        self::assertSame(1, $clone['data']['summary']['endpoints_created']);
        self::assertNotSame($mockId, $clone['data']['items'][0]['mock_id']);
        $run = TestRun::where('public_id', $this->run['run_id'])->first();
        self::assertSame(2, $run->endpoints()->count());
        self::assertSame(2, $this->callTool('get_mock', [...$this->run, 'mock_id' => $mockId])['data']['version']);
        // Duplicate entries within one file follow the same first-item-only rule.
        $document = json_decode($this->routableCollection(), true);
        $document['item'][0]['item'][0]['item'][] = $document['item'][0]['item'][0]['item'][0];
        $another = ['run_id' => $this->callTool('start_test_run', ['label' => 'duplicate'])['data']['run_id'], 'collection' => json_encode($document)];
        self::assertSame(['create', 'skip'], array_column($this->callTool('preview_postman_import', $another)['data']['items'], 'action'));
        self::assertSame(1, $this->callTool('apply_postman_import', [...$another, 'idempotency_key' => 'duplicate'])['data']['summary']['endpoints_created']);
    }

    public function test_validation_and_mid_write_failures_roll_back_all_import_state_and_write_permission_is_independent(): void
    {
        $args = [...$this->run, 'collection' => $this->routableCollection(), 'idempotency_key' => 'atomic'];
        $before = $this->configurationSnapshot();
        $invalid = json_decode($args['collection'], true);
        $invalid['item'][0]['item'][0]['item'][0]['request']['url'] = '/mcp';
        self::assertFalse($this->callTool('preview_postman_import', [...$this->run, 'collection' => json_encode($invalid)])['data']['can_apply']);
        self::assertSame('VALIDATION_ERROR', $this->callTool('apply_postman_import', [...$args, 'collection' => json_encode($invalid)])['error']['code']);
        self::assertSame($before, $this->configurationSnapshot());
        $failOnce = true;
        MockResponse::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Simulated response insert failure');
            }
        });
        self::assertSame('VALIDATION_ERROR', $this->callTool('apply_postman_import', $args)['error']['code']);
        self::assertSame($before, $this->configurationSnapshot());
        self::assertSame(0, DB::table('mcp_idempotency_keys')->count());
        // Failure did not poison the retry key. Write alone is enough to import.
        $this->owner->update(['can_read' => false, 'can_verify' => false, 'can_cleanup' => false]);
        self::assertTrue($this->callTool('apply_postman_import', $args)['ok']);
        self::assertSame('FORBIDDEN', $this->callTool('preview_postman_import', [...$this->run, 'collection' => $args['collection']])['error']['code']);
        $this->owner->update(['can_read' => true, 'can_write' => false]);
        self::assertSame('FORBIDDEN', $this->callTool('apply_postman_import', [...$args, 'idempotency_key' => 'denied'])['error']['code']);
    }

    public function test_mapped_templates_use_run_environment_and_neither_tool_has_script_execution_paths(): void
    {
        $run = TestRun::where('public_id', $this->run['run_id'])->first();
        $run->environment->variables()->create(['key' => 'cartId', 'value' => 'run-cart', 'is_secret' => false]);
        $collection = json_decode($this->collection(), true);
        $collection['item'][0]['item'][0]['item'][0]['request']['header'] = [];
        $result = $this->callTool('apply_postman_import', [...$this->run, 'collection' => json_encode($collection), 'idempotency_key' => 'templates']);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('template', $run->endpoints()->sole()->responses()->sole()->body_mode);
        $response = $this->get('/cart?id=anything', [RunRouting::HEADER => $run->public_id])->assertOk()->assertJsonPath('cart', 'run-cart');
        self::assertNotFalse(filter_var($response->json('email'), FILTER_VALIDATE_EMAIL));
        self::assertSame(['cartId'], $run->environment->variables()->pluck('key')->all());
        foreach (['app/Services/Mcp/McpPostmanImport.php', 'app/Services/Imports/PostmanMapper.php', 'app/Services/Imports/MappedImportService.php', 'app/Services/Imports/PostmanResponseVariables.php', 'app/Services/Imports/PostmanVariableCatalog.php', 'app/Services/Imports/PostmanFieldResolver.php'] as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(eval|exec|shell_exec|system|passthru|proc_open|popen)\s*\(|new\s+Process\b|\b(Node|V8Js|V8|JavaScript)\s*::/i', file_get_contents(base_path($file)), $file);
        }
        foreach (['mcp_invocations', 'mcp_idempotency_keys', 'revisions', 'mock_endpoints', 'mock_responses'] as $table) {
            self::assertStringNotContainsString('pm.environment.set', json_encode(DB::table($table)->get()));
            self::assertStringNotContainsString('script-value', json_encode(DB::table($table)->get()));
        }
    }

    private function routableCollection(int $status = 200): string
    {
        $document = json_decode($this->collection(), true);
        $item = &$document['item'][0]['item'][0]['item'][0];
        $item['request']['header'] = [];
        $item['response'][0]['code'] = $status;
        $item['response'][0]['body'] = '{"imported":true}';

        return json_encode($document);
    }

    private function configurationSnapshot(): array
    {
        return array_intersect_key($this->snapshot(), array_flip(['mock_endpoints', 'mock_responses', 'collections', 'revisions', 'environment_variables', 'endpoint_call_state']));
    }

    private function collection(array $info = []): string
    {
        $item = ['name' => 'Get cart',
            'event' => [['listen' => 'prerequest', 'script' => ['exec' => ['pm.environment.set("notExecuted", "script-value");']]], ['listen' => 'test', 'script' => ['exec' => ['throw new Error("never executed");']]]],
            'request' => ['method' => 'GET', 'url' => ['path' => ['cart'], 'query' => [['key' => 'id', 'value' => '{{cartId}}']]], 'header' => [['key' => 'Authorization', 'value' => 'Bearer {{stg_token}}'], ['key' => 'Cookie', 'value' => 'session=credential-value']]],
            'response' => [['name' => 'Saved', 'code' => 200, 'header' => [['key' => 'Content-Type', 'value' => 'application/json']], 'body' => '{"cart":"{{cartId}}","email":"{{$randomEmail}}"}']],
        ];

        return json_encode(['info' => [...$info, 'name' => 'Agent fixtures', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'variable' => [['key' => 'cartId', 'value' => 'embedded']],
            'item' => [['name' => 'Auth', 'item' => [['name' => 'Login', 'item' => [$item]]]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function callTool(string $name, array $args): array
    {
        return app(McpDispatcher::class)->call($name, $args, $this->owner);
    }

    private function envelope(string $tool, array $args): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]];
    }

    private function snapshot(): array
    {
        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            $tables[$table] = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $tables;
    }
}
