<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\McpInvocation;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\TestRuns\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpReadToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_and_read_tools_are_scoped_redacted_and_side_effect_free(): void
    {
        $issued = app(McpCredentialService::class)->generate('reader', ['read', 'write', 'verify']);
        $owner = $issued->credential;
        $run = app(TestRunService::class)->create($owner, app(EnvironmentContext::class)->active(), 'read test');
        $mock = app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: "curl 'http://localhost/example?ignored=old' -H 'Authorization: Bearer super-secret'", excludedQueryParams: ['ignored']), run: $run);
        $mock->responses()->create(['body' => 'hello']);
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $args = ['run_id' => $run->public_id, 'mock_id' => $mock->uuid];
        $result = $call('get_mock', $args);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertStringNotContainsString('super-secret', json_encode($result));
        self::assertSame(1, $call('list_mocks', ['run_id' => $run->public_id])['data']['total']);
        self::assertTrue($call('validate_mock', ['configuration' => ['curl' => 'curl http://localhost/new']])['ok']);
        $explain = $call('explain_match', ['run_id' => $run->public_id, 'curl' => 'curl http://localhost/example?ignored=new']);
        self::assertTrue($explain['ok'], json_encode($explain));
        self::assertSame($mock->uuid, $explain['data']['selected']);
        self::assertSame(0, EndpointCallState::query()->count());
        self::assertSame(4, McpInvocation::query()->count());
        $other = app(McpCredentialService::class)->generate('other', ['read'])->credential;
        self::assertSame('RUN_NOT_FOUND', app(McpDispatcher::class)->call('get_mock', $args, $other)['error']['code']);
        $headers = ['Authorization' => 'Bearer '.$issued->reveal()];
        $page = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers)
            ->assertOk()->assertJsonCount(15, 'result.tools');
        $next = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['cursor' => $page->json('result.nextCursor')]], $headers)
            ->assertOk()->assertJsonCount(1, 'result.tools');
        $names = array_column([...$page->json('result.tools'), ...$next->json('result.tools')], 'name');
        self::assertCount(16, array_unique($names));
        self::assertContains('preview_template', $names);
    }
}
