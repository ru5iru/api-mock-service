<?php

namespace Tests\Feature;

use App\Models\McpInvocation;
use App\Models\McpUnmatchedRequest;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\TestRuns\RunRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class McpSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_permissions_and_untrusted_envelopes_are_audited_and_fail_closed(): void
    {
        $issued = app(McpCredentialService::class)->generate('read only', ['read']);
        $headers = ['Authorization' => 'Bearer '.$issued->reveal()];
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'start_test_run', 'arguments' => ['label' => 'not allowed']]];
        $this->postJson('/mcp', $envelope, $headers)->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $envelope['params']['name'] = 'unknown_tool';
        $this->postJson('/mcp', $envelope, $headers)->assertStatus(400);
        self::assertSame(2, McpInvocation::query()->count());
        $envelope['params'] = ['name' => 'validate_mock', 'arguments' => ['configuration' => ['request' => ['url' => '/valid', 'extra' => 'bad']]]];
        $this->postJson('/mcp', $envelope, $headers)->assertOk()->assertJsonPath('result.structuredContent.error.code', 'VALIDATION_ERROR');
        $this->postJson('/mcp', $envelope, [...$headers, 'Origin' => 'https://untrusted.test'])->assertForbidden();
        self::assertSame(0, DB::table('test_runs')->count());
    }

    public function test_redaction_covers_results_cache_history_and_audit_and_cleanup_cannot_cross_scopes(): void
    {
        $this->freezeTime();
        app(EnvironmentContext::class)->active()->variables()->create(['key' => 'API_TOKEN', 'value' => 'private-environment-value', 'is_secret' => true]);
        $owner = app(McpCredentialService::class)->generate('owner', ['read', 'write', 'verify', 'cleanup'])->credential;
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $ordinary = MockEndpoint::factory()->create();
        $ordinary->responses()->create(['body' => 'ordinary']);
        $export = app(ConfigExporter::class)->export()->data;
        $scope = ['run_id' => $call('start_test_run', ['label' => 'secrets'])['data']['run_id']];
        $other = ['run_id' => $call('start_test_run', ['label' => 'other'])['data']['run_id']];
        $config = ['curl' => "curl 'http://localhost/secret?api_key=private-query-value' -H 'Authorization: Bearer private-auth-value'", 'responses' => [['headers' => ['Set-Cookie' => 'private-cookie-value'], 'body' => '{"password":"private-body-value","context":"private-environment-value"}']]];
        $result = $call('create_mock', [...$scope, 'idempotency_key' => 'secret', 'configuration' => $config]);
        self::assertTrue($result['ok'], json_encode($result));
        $second = $call('create_mock', [...$other, 'idempotency_key' => 'other', 'configuration' => $config]);
        self::assertTrue($second['ok']);
        self::assertSame(2, $call('list_test_runs', [])['data']['runs']['total']);
        self::assertEquals($export, app(ConfigExporter::class)->export()->data);
        $inspect = [...$scope, 'mock_id' => $result['data']['mock_id']];
        $sensitive = ['private-query-value', 'private-auth-value', 'private-cookie-value', 'private-body-value', 'private-environment-value'];
        foreach ([$result, $call('get_mock', $inspect), DB::table('mcp_idempotency_keys')->get()->toArray(), McpInvocation::all()->toArray(), Revision::all()->toArray()] as $output) {
            foreach ($sensitive as $secret) {
                self::assertStringNotContainsString($secret, json_encode($output));
            }
        }
        self::assertTrue($call('finish_test_run', [...$scope, 'cleanup' => 'delete_mocks'])['ok']);
        self::assertSame(1, $call('list_mocks', $other)['data']['total']);
        self::assertTrue(MockEndpoint::query()->whereKey($ordinary->id)->exists());
        self::assertSame('RUN_FINISHED', $call('reset_test_run', $scope)['error']['code']);
    }

    public function test_unmatched_window_is_ordered_bounded_encrypted_and_prunable(): void
    {
        $owner = app(McpCredentialService::class)->generate('window', ['read', 'write', 'verify'])->credential;
        $runId = app(McpDispatcher::class)->call('start_test_run', ['label' => 'window'], $owner)['data']['run_id'];
        for ($i = 0; $i < 22; $i++) {
            $this->get('/window?i='.$i, [RunRouting::HEADER => $runId, 'Authorization' => 'Bearer secret-evidence'])->assertNotFound();
        }
        self::assertSame(20, McpUnmatchedRequest::count());
        $newest = McpUnmatchedRequest::orderByDesc('sequence')->firstOrFail();
        self::assertStringContainsString('i=21', $newest->request['url']);
        self::assertStringNotContainsString('secret-evidence', DB::table('mcp_unmatched_requests')->value('request'));
        DB::table('mcp_unmatched_requests')->update(['created_at' => now()->subDays(2)]);
        $this->artisan('mockdeck:mcp-prune')->assertExitCode(0);
        self::assertSame(0, McpUnmatchedRequest::count());
    }
}
