<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\Environment;
use App\Models\McpCredential;
use App\Models\McpInvocation;
use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\TestRuns\RunRouting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class McpTemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private McpCredential $owner;

    private string $token;

    private array $run;

    protected function setUp(): void
    {
        parent::setUp();
        $issued = app(McpCredentialService::class)->generate('preview', ['read', 'write', 'verify', 'cleanup']);
        $this->owner = $issued->credential;
        $this->token = $issued->reveal();
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'preview'])['data']['run_id']];
    }

    public function test_valid_catalog_tokens_preserve_typed_sample_shape_seed_and_dashboard_label(): void
    {
        $this->readOnly();
        $template = '{"id":"$number.int({\"min\":9,\"max\":9})","email":"$internet.email","name":"$person.fullName","empty":{},"rows":[]}';
        $args = [...$this->run, 'template' => $template, 'locale' => 'en', 'seed' => 42];
        $expected = app(ResponseTemplateEngine::class)->preview($template, 'en', 'fixed', 42);
        $before = $this->snapshot();
        $result = $this->callTool('preview_template', $args);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertEquals($expected['output'], $result['data']['output']);
        self::assertSame(9, $result['data']['output']->id);
        self::assertNotFalse(filter_var($result['data']['output']->email, FILTER_VALIDATE_EMAIL));
        self::assertInstanceOf(\stdClass::class, $result['data']['output']->empty);
        self::assertSame([], $result['data']['output']->rows);
        self::assertSame($expected['issues'], $result['data']['issues']);
        self::assertFalse($result['data']['synthetic_context']);
        self::assertSame(TemplateContext::PREVIEW_NOTICE, $result['data']['sample_notice']);
        self::assertGreaterThan(0, $result['data']['bytes']);
        self::assertEquals($result['data']['output'], $this->callTool('preview_template', $args)['data']['output']);
        $random = $this->callTool('preview_template', [...$this->run, 'template' => '{"id":"$string.uuid"}', 'seed' => 'random']);
        self::assertTrue($random['ok'], json_encode($random));
        self::assertTrue(Str::isUuid($random['data']['output']->id));
        self::assertSame($before, $this->snapshot());
    }

    public function test_unknown_token_issues_are_identical_to_dashboard_and_invalid_json_uses_the_same_shape(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $template = "{\n  \"bad\": \"\$person.fristName\"\n}";
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('setBodyMode', 'template')->set('template', $template)->call('previewTemplate');
        $expected = $editor->get('templateIssues');
        $this->readOnly();
        $before = $this->snapshot();
        $result = $this->callTool('preview_template', [...$this->run, 'template' => $template]);
        self::assertSame('VALIDATION_ERROR', $result['error']['code']);
        self::assertSame($expected, $result['error']['details']['issues']);
        self::assertSame('UNKNOWN_METHOD', $result['error']['details']['issues'][0]['code']);
        self::assertNull($result['error']['details']['output']);
        $badJson = $this->callTool('preview_template', [...$this->run, 'template' => '{broken']);
        self::assertSame(app(ResponseTemplateEngine::class)->validate('{broken')->issueArrays(), $badJson['error']['details']['issues']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_saved_response_preview_uses_bound_environment_and_synthetic_path_without_write_permission(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $active->variables()->create(['key' => 'KEY', 'value' => 'global', 'is_secret' => false]);
        $bound = Environment::create(['name' => 'Run preview', 'is_default' => false]);
        $bound->variables()->create(['key' => 'KEY', 'value' => 'bound', 'is_secret' => false]);
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'bound', 'environment' => $bound->name])['data']['run_id']];
        $created = $this->createFixture('curl http://localhost/users/{id}', '{"env":"{{env.KEY}}","id":"$request.path.id","method":"$request.method","url":"$request.url","request":"$request.id"}', ['matching' => ['path_pattern_enabled' => true]]);
        $this->get('/users/real-id', [RunRouting::HEADER => $this->run['run_id'], 'X-Request-ID' => 'live-request'])->assertOk()->assertJsonPath('id', 'real-id');
        $this->readOnly();
        $before = $this->snapshot();
        $result = $this->callTool('preview_template', $this->reference($created));
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('bound', $result['data']['output']->env);
        self::assertSame('sample_id', $result['data']['output']->id);
        self::assertSame('GET', $result['data']['output']->method);
        self::assertSame('preview', $result['data']['output']->request);
        self::assertStringContainsString('/users/sample_id', $result['data']['output']->url);
        self::assertTrue($result['data']['synthetic_context']);
        self::assertSame(TemplateContext::PREVIEW_NOTICE, $result['data']['sample_notice']);
        self::assertSame($active->id, app(EnvironmentContext::class)->active()->id);
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->owner->fresh()->can_write);
    }

    public function test_http_preview_leaves_every_database_table_and_credential_timestamp_byte_identical(): void
    {
        $created = $this->createFixture('curl http://localhost/saved-preview', '{"name":"$person.fullName"}');
        $this->readOnly();
        $this->travel(60)->seconds();
        $before = $this->snapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'preview_template', 'arguments' => $this->reference($created)]];
        $this->postJson('/mcp', $envelope, ['Authorization' => 'Bearer '.$this->token])->assertOk()
            ->assertJsonPath('result.structuredContent.ok', true)->assertJsonPath('result.structuredContent.data.sample_notice', TemplateContext::PREVIEW_NOTICE);
        $envelope['params']['arguments'] = [...$this->run, 'template' => '{"bad":"$person.fristName"}'];
        $this->postJson('/mcp', $envelope, ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonPath('result.isError', true);
        // Also exercise SDK-rejected arguments, before the domain handler runs.
        $envelope['params']['arguments'] = [...$this->run, 'template' => 123];
        $this->postJson('/mcp', $envelope, ['Authorization' => 'Bearer '.$this->token])->assertOk();
        self::assertSame([], $writes);
        self::assertSame($before, $this->snapshot());
    }

    public function test_secret_bound_variables_stay_hidden_and_nested_sample_secrets_are_redacted(): void
    {
        $environment = app(EnvironmentContext::class)->active();
        $environment->variables()->create(['key' => 'SECRET', 'value' => 'unique-private-value', 'is_secret' => true]);
        $this->readOnly();
        $before = $this->snapshot();
        $secret = $this->callTool('preview_template', [...$this->run, 'template' => '{"x":"{{env.SECRET}}"}']);
        self::assertSame('SECRET_PREVIEW_HIDDEN', $secret['error']['details']['issues'][0]['code']);
        self::assertStringNotContainsString('unique-private-value', json_encode($secret));
        $literal = $this->callTool('preview_template', [...$this->run, 'template' => '{"nested":{"password":"raw-private","safe":"unique-private-value"},"empty":{}}']);
        self::assertTrue($literal['ok'], json_encode($literal));
        self::assertSame('[REDACTED]', $literal['data']['output']->nested->password);
        self::assertSame('[REDACTED]', $literal['data']['output']->nested->safe);
        self::assertInstanceOf(\stdClass::class, $literal['data']['output']->empty);
        self::assertSame($before, $this->snapshot());
    }

    public function test_ownership_reference_shape_seed_and_read_permission_are_enforced_without_persistence(): void
    {
        $created = $this->createFixture('curl http://localhost/reference', '{"ok":true}');
        $second = $this->createFixture('curl http://localhost/other-reference', '{"ok":true}');
        $this->readOnly();
        $args = $this->reference($created);
        $before = $this->snapshot();
        $foreignResponse = $this->callTool('preview_template', [...$args, 'response_id' => $second['data']['responses'][0]['id']]);
        self::assertSame('NOT_FOUND', $foreignResponse['error']['code']);
        foreach ([
            [...$this->run], [...$this->run, 'response_id' => $args['response_id']],
            [...$args, 'template' => '{}'], [...$this->run, 'template' => '{}', 'seed' => 'fixed'],
            [...$this->run, 'template' => '{}', 'seed' => 1.5], [...$this->run, 'template' => '{}', 'seed' => null],
        ] as $invalid) {
            self::assertSame('VALIDATION_ERROR', $this->callTool('preview_template', $invalid)['error']['code']);
        }
        self::assertSame($before, $this->snapshot());
        $other = app(McpCredentialService::class)->generate('other reader', ['read'])->credential;
        self::assertSame('RUN_NOT_FOUND', app(McpDispatcher::class)->call('preview_template', $args, $other)['error']['code']);
        $this->owner->update(['can_read' => false, 'can_write' => true]);
        self::assertSame('FORBIDDEN', $this->callTool('preview_template', $args)['error']['code']);
    }

    public function test_preview_exception_does_not_disable_authentication_usage_or_auditing_for_other_tools(): void
    {
        $created = $this->createFixture('curl http://localhost/ordinary-read', '{"ok":true}');
        $this->readOnly();
        $auditCount = McpInvocation::count();
        $this->travel(60)->seconds();
        $beforeUsage = $this->owner->fresh()->last_used_at;
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_mock',
            'arguments' => [...$this->run, 'mock_id' => $created['data']['mock_id']]]], ['Authorization' => 'Bearer '.$this->token])->assertOk();
        self::assertSame($auditCount + 1, McpInvocation::count());
        self::assertNotEquals($beforeUsage, $this->owner->fresh()->last_used_at);
        app(McpCredentialService::class)->revoke($this->owner->id);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'preview_template',
            'arguments' => [...$this->run, 'template' => '{}']]], ['Authorization' => 'Bearer '.$this->token])->assertUnauthorized();
    }

    private function snapshot(): string
    {
        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row))->sort()->values()->all();
        }
        ksort($tables);

        return json_encode($tables, JSON_THROW_ON_ERROR);
    }

    private function readOnly(): void
    {
        $this->owner->update(['can_write' => false, 'can_verify' => false, 'can_cleanup' => false]);
    }

    private function createFixture(string $curl, string $template, array $fields = []): array
    {
        $result = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => $curl,
            'configuration' => ['curl' => $curl, 'responses' => [['body_mode' => 'template', 'body' => $template]], ...$fields]]);
        self::assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function reference(array $created): array
    {
        return [...$this->run, 'mock_id' => $created['data']['mock_id'], 'response_id' => $created['data']['responses'][0]['id']];
    }

    private function callTool(string $name, array $args): array
    {
        return app(McpDispatcher::class)->call($name, $args, $this->owner);
    }
}
