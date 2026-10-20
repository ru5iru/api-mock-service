<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\McpInvocation;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\TestRuns\RunRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_workflow_idempotency_conflicts_routing_evidence_reset_and_finish(): void
    {
        $owner = app(McpCredentialService::class)->generate('workflow', ['read', 'write', 'verify', 'cleanup'])->credential;
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $run = $call('start_test_run', ['label' => 'P0']);
        self::assertTrue($run['ok'], json_encode($run));
        $scope = ['run_id' => $run['data']['run_id']];
        $create = [...$scope, 'idempotency_key' => 'create-1', 'configuration' => ['curl' => 'curl http://localhost/p0?x=1', 'responses' => [['body' => '{"ok":true}']]]];
        $created = $call('create_mock', $create);
        self::assertTrue($created['ok'], json_encode($created));
        $mock = [...$scope, 'mock_id' => $created['data']['mock_id']];
        self::assertSame($created['data'], $call('create_mock', $create)['data']);
        self::assertSame('IDEMPOTENCY_CONFLICT', $call('create_mock', [...$create, 'configuration' => [...$create['configuration'], 'name' => 'different']])['error']['code']);
        self::assertSame('CONFLICT', $call('create_mock', [...$create, 'idempotency_key' => 'duplicate'])['error']['code']);
        $invalid = [...$create, 'idempotency_key' => 'invalid', 'configuration' => ['curl' => 'curl http://localhost/invalid', 'responses' => [['body' => 'first'], ['headers' => ['X-Bad' => "value\r\ninjected"]]]]];
        self::assertSame('VALIDATION_ERROR', $call('create_mock', $invalid)['error']['code']);
        self::assertSame(1, MockEndpoint::withoutGlobalScope('ordinary')->count());
        self::assertSame(1, MockResponse::withoutGlobalScope('ordinary')->count());
        $this->get('/p0?x=1', [RunRouting::HEADER => $scope['run_id']])->assertOk()->assertJsonPath('ok', true);
        $this->get(RunRouting::PREFIX.$scope['run_id'].'/p0?x=1')->assertOk();
        $this->get('/p0?x=1')->assertNotFound();
        self::assertSame(2, $call('get_calls', $mock)['data']['total_match_count']);
        self::assertTrue($call('assert_calls', [...$mock, 'expect_count' => 2, 'comparator' => 'equals'])['ok']);
        self::assertSame('ASSERTION_FAILED', $call('assert_calls', [...$mock, 'expect_count' => 3, 'comparator' => 'equals'])['error']['code']);
        $update = [...$mock, 'expected_version' => 1, 'changes' => ['endpoint' => ['name' => 'renamed'], 'responses' => ['update' => [['id' => $created['data']['responses'][0]['id'], 'body' => '{"new":true}']]]]];
        $updated = $call('update_mock', $update);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame(2, $updated['data']['version']);
        self::assertSame('CONFLICT', $call('update_mock', $update)['error']['code']);
        self::assertSame('renamed', $call('get_mock', $mock)['data']['name']);
        $configBefore = MockEndpoint::withoutGlobalScope('ordinary')->first()->getAttributes();
        self::assertTrue($call('reset_test_run', $scope)['ok']);
        self::assertSame($configBefore, MockEndpoint::withoutGlobalScope('ordinary')->first()->getAttributes());
        self::assertSame(0, $call('get_calls', $mock)['data']['total_match_count']);
        $finish = $call('finish_test_run', [...$scope, 'cleanup' => 'delete_mocks']);
        self::assertTrue($finish['ok'], json_encode($finish));
        self::assertSame($finish['data'], $call('finish_test_run', [...$scope, 'cleanup' => 'keep_mocks'])['data']);
        self::assertSame(0, MockEndpoint::withoutGlobalScope('ordinary')->count());
        self::assertSame(4, Revision::query()->count());
        self::assertSame(0, EndpointCallState::query()->count());
        self::assertSame($created['data'], $call('create_mock', $create)['data']);
        self::assertSame(0, MockEndpoint::withoutGlobalScope('ordinary')->count());
        self::assertSame(17, McpInvocation::query()->count());
    }

    public function test_unmatched_log_reference_is_equivalent_and_filtered_evidence_is_honest(): void
    {
        $owner = app(McpCredentialService::class)->generate('evidence', ['read', 'write', 'verify', 'cleanup'])->credential;
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $scope = ['run_id' => $call('start_test_run', ['label' => 'evidence'])['data']['run_id']];
        $created = $call('create_mock', [...$scope, 'idempotency_key' => 'one', 'configuration' => ['curl' => 'curl http://localhost/evidence?q=good', 'responses' => [['body' => 'good']]]]);
        self::assertTrue($created['ok'], json_encode($created));
        $this->get('/evidence?q=bad', [RunRouting::HEADER => $scope['run_id']])->assertNotFound();
        $logs = $call('get_unmatched_requests', $scope);
        self::assertTrue($logs['ok'], json_encode($logs));
        self::assertCount(1, $logs['data']['items']);
        $byLog = $call('explain_match', [...$scope, 'log_entry_id' => $logs['data']['items'][0]['id']]);
        $byCurl = $call('explain_match', [...$scope, 'curl' => 'curl http://localhost/evidence?q=bad']);
        self::assertTrue($byLog['ok'], json_encode($byLog));
        self::assertSame($byCurl['data']['selected'], $byLog['data']['selected']);
        self::assertSame($byCurl['data']['candidates'][0]['differences'], $byLog['data']['candidates'][0]['differences']);
        $mock = [...$scope, 'mock_id' => $created['data']['mock_id']];
        for ($i = 0; $i < 21; $i++) {
            $this->get('/evidence?q=good', [RunRouting::HEADER => $scope['run_id']])->assertOk();
        }
        $result = $call('assert_calls', [...$mock, 'expect_count' => 21, 'comparator' => 'equals', 'matching' => [['field_type' => 'query', 'field_name' => 'q', 'operator' => 'equals', 'value' => 'good']]]);
        self::assertSame('INCONCLUSIVE_EVIDENCE', $result['error']['code']);
        self::assertSame(1, $result['error']['details']['window']['unretained_calls']);
    }

    public function test_template_matching_and_response_pool_patches_reuse_existing_services(): void
    {
        app(EnvironmentContext::class)->active()->variables()->create(['key' => 'NAME', 'value' => 'bound fixture', 'is_secret' => false]);
        $owner = app(McpCredentialService::class)->generate('template patch', ['read', 'write', 'verify', 'cleanup'])->credential;
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $run = ['run_id' => $call('start_test_run', ['label' => 'template'])['data']['run_id']];
        $created = $call('create_mock', [...$run, 'idempotency_key' => 'template', 'configuration' => [
            'request' => ['url' => 'http://localhost/users/{id}?trace=one', 'headers' => ['X-Nonce' => 'one']],
            'matching' => ['path_pattern_enabled' => true, 'excluded_query_params' => ['trace'], 'excluded_headers' => ['x-nonce']],
            'responses' => [['body_mode' => 'template', 'body' => '{"id":"{{$request.path.id}}","env":"{{env.NAME}}","uuid":"$string.uuid"}', 'seed_mode' => 'fixed', 'seed' => 42]],
        ]]);
        self::assertTrue($created['ok'], json_encode($created));
        $target = [...$run, 'mock_id' => $created['data']['mock_id']];
        $response = $this->get('/users/42?trace=two', [RunRouting::HEADER => $run['run_id'], 'X-Nonce' => 'different']);
        $response->assertOk()->assertJsonPath('id', '42')->assertJsonPath('env', 'bound fixture');
        self::assertTrue(Str::isUuid($response->json('uuid')));
        self::assertTrue($call('get_mock_url', $target)['ok']);
        $updated = $call('update_mock', [...$target, 'expected_version' => 1, 'changes' => [
            'endpoint' => ['priority' => 9], 'responses' => ['remove' => [$created['data']['responses'][0]['id']], 'add' => [['status' => 201, 'body' => 'patched']]],
        ]]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame(2, $updated['data']['version']);
        self::assertSame($created['data']['curl'], $updated['data']['curl']);
        self::assertSame(1, count($updated['data']['responses']));
        $this->get('/users/abc?trace=three', [RunRouting::HEADER => $run['run_id']])->assertStatus(201)->assertContent('patched');
        $noOp = $call('update_mock', [...$target, 'expected_version' => 2, 'changes' => []]);
        self::assertTrue($noOp['ok']);
        self::assertSame(2, $noOp['data']['version']);
    }
}
