<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\EndpointCallState;
use App\Models\McpCredential;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Response\FaultInjectionService;
use App\Services\TestRuns\RunRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

final class McpFaultTest extends TestCase
{
    use RefreshDatabase;

    private McpCredential $owner;

    private array $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = app(McpCredentialService::class)->generate('faults', ['read', 'write', 'verify', 'cleanup'])->credential;
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'faults'])['data']['run_id']];
    }

    public function test_delay_is_measurable_still_counts_and_keeps_the_existing_request_log_marker(): void
    {
        $created = $this->create('/mcp-delay', ['fault_enabled' => true, 'fault_type' => 'delay', 'fault_delay_ms_min' => 30, 'fault_delay_ms_max' => 30, 'fault_probability' => 100]);
        $endpoint = MockEndpoint::withoutGlobalScope('ordinary')->where('uuid', $created['data']['mock_id'])->firstOrFail();
        Log::shouldReceive('channel')->with('mock_requests')->once()->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(fn (string $level, string $message, array $event) => $message === 'mock_request'
            && $event['fault_applied'] === 'delay' && $event['fault_delay_ms'] === 30 && $event['endpoint_id'] === $endpoint->id);
        $start = hrtime(true);
        $this->get('/mcp-delay', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent('{"ok":true}');
        self::assertGreaterThanOrEqual(29, (hrtime(true) - $start) / 1_000_000);
        $calls = $this->callTool('get_calls', $this->target($created));
        self::assertTrue($calls['ok'], json_encode($calls));
        self::assertSame(1, $calls['data']['total_match_count']);
        self::assertCount(1, $calls['data']['recent_calls']);
        self::assertSame('/mcp-delay', $calls['data']['recent_calls'][0]['path']);
    }

    public function test_invalid_faults_fail_identically_in_dry_runs_and_creates_or_updates_without_writes(): void
    {
        $created = $this->create('/existing-fault');
        $target = $this->target($created);
        $response = MockResponse::withoutGlobalScope('ordinary')->sole();
        $before = $response->getRawOriginal();
        $invalid = [
            ['fault_enabled' => true, 'fault_type' => 'connection_reset'],
            ['fault_enabled' => false, 'fault_type' => 'connection_reset'],
            ['fault_type' => 'made_up'],
            ['fault_delay_ms_min' => -1],
            ['fault_delay_ms_max' => 120001],
            ['fault_probability' => 101],
            ['fault_probability' => -1],
            ['fault_delay_ms_min' => 20, 'fault_delay_ms_max' => 10],
            ['fault_enabled' => true, 'fault_type' => 'malformed_body', 'headers' => ['Content-Type' => 'text/plain']],
        ];
        foreach ($invalid as $index => $fields) {
            $config = ['curl' => 'curl http://localhost/invalid-fault-'.$index, 'responses' => [$fields]];
            $dry = $this->callTool('validate_mock', ['configuration' => $config]);
            $write = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => 'invalid-'.$index, 'configuration' => $config]);
            self::assertSame('VALIDATION_ERROR', $dry['error']['code'], json_encode($dry));
            self::assertSame('VALIDATION_ERROR', $write['error']['code'], json_encode($write));
            self::assertSame($dry['error']['details'], $write['error']['details']);
            $changes = ['responses' => ['update' => [['id' => $response->uuid, ...$fields]]]];
            $dryPatch = $this->callTool('validate_mock', [...$target, 'changes' => $changes]);
            $update = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => $changes]);
            self::assertSame('VALIDATION_ERROR', $update['error']['code'], json_encode($update));
            self::assertSame($dryPatch['error']['details'], $update['error']['details']);
            self::assertSame($before, $response->fresh()->getRawOriginal());
        }
        self::assertSame(1, MockEndpoint::withoutGlobalScope('ordinary')->count());
        self::assertSame(1, MockResponse::withoutGlobalScope('ordinary')->count());
        self::assertSame(1, Revision::count());
        self::assertSame(0, EndpointCallState::count());
    }

    public function test_omission_keeps_non_faulty_bytes_and_defaults_and_zero_probability_disables_faults(): void
    {
        $body = "raw bytes\n";
        $created = $this->create('/no-fault', ['body' => $body, 'headers' => ['Content-Type' => 'text/plain', 'X-Test' => 'same']]);
        self::assertSame(FaultConfigurationValidator::defaults(), array_intersect_key($created['data']['responses'][0], FaultConfigurationValidator::defaults()));
        self::assertNull(app(FaultInjectionService::class)->decide(MockResponse::withoutGlobalScope('ordinary')->sole()));
        $this->get('/no-fault', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent($body)->assertHeader('X-Test', 'same');
        $patch = ['responses' => ['update' => [['id' => $created['data']['responses'][0]['id'], 'weight' => 2]]]];
        $updated = $this->callTool('update_mock', [...$this->target($created), 'expected_version' => 1, 'changes' => $patch]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertFalse($updated['data']['responses'][0]['fault_enabled']);
        $zero = $this->callTool('update_mock', [...$this->target($created), 'expected_version' => 2, 'changes' => ['responses' => ['update' => [[
            'id' => $created['data']['responses'][0]['id'], 'fault_enabled' => true, 'fault_type' => 'truncated_body', 'fault_probability' => 0,
        ]]]]]);
        self::assertTrue($zero['ok'], json_encode($zero));
        $this->get('/no-fault', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent($body)->assertHeader('X-Test', 'same');
        self::assertSame(2, $this->callTool('get_calls', $this->target($created))['data']['total_match_count']);
    }

    public function test_partial_updates_preserve_fault_settings_and_nullable_max_can_restore_fixed_delay(): void
    {
        $fields = ['fault_enabled' => true, 'fault_type' => 'delay', 'fault_delay_ms_min' => 5, 'fault_delay_ms_max' => 10, 'fault_probability' => 42];
        $created = $this->create('/fault-patch', $fields);
        $target = $this->target($created);
        $id = $created['data']['responses'][0]['id'];
        $updated = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => ['responses' => ['update' => [['id' => $id, 'body' => 'changed']]]]]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame($fields, array_intersect_key($updated['data']['responses'][0], $fields));
        $cleared = $this->callTool('update_mock', [...$target, 'expected_version' => 2, 'changes' => ['responses' => ['update' => [['id' => $id, 'fault_delay_ms_max' => null]]]]]);
        self::assertTrue($cleared['ok'], json_encode($cleared));
        self::assertNull($cleared['data']['responses'][0]['fault_delay_ms_max']);
        self::assertSame(5, $cleared['data']['responses'][0]['fault_delay_ms_min']);
        $read = $this->callTool('get_mock', $target);
        self::assertSame($cleared['data']['responses'], $read['data']['responses']);
        $noOp = $this->callTool('update_mock', [...$target, 'expected_version' => 3, 'changes' => []]);
        self::assertTrue($noOp['ok'], json_encode($noOp));
        self::assertSame(3, $noOp['data']['version']);
    }

    public function test_all_shipped_types_use_the_existing_body_transform_and_bounded_timeout_engine(): void
    {
        foreach (FaultConfigurationValidator::TYPES as $type) {
            $created = $this->create('/type-'.$type, ['fault_enabled' => true, 'fault_type' => $type,
                'fault_delay_ms_min' => 1, 'fault_probability' => 100]);
            self::assertSame($type, $created['data']['responses'][0]['fault_type']);
            $response = $this->get('/type-'.$type, [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertHeader('Content-Type', 'application/json');
            $expected = match ($type) {
                'malformed_body' => '{"mockdeck_fault":',
                'truncated_body' => substr('{"ok":true}', 0, intdiv(strlen('{"ok":true}'), 2)),
                default => '{"ok":true}',
            };
            $response->assertContent($expected);
            if (in_array($type, ['malformed_body', 'truncated_body'], true)) {
                json_decode($response->getContent());
                self::assertNotSame(JSON_ERROR_NONE, json_last_error());
            }
            self::assertSame(1, $this->callTool('get_calls', $this->target($created))['data']['total_match_count']);
        }
    }

    public function test_faults_compose_with_sequence_and_rule_selection_and_peek_does_not_apply_them(): void
    {
        $sequence = $this->create('/sequence-fault', [], ['selection_mode' => 'sequence', 'responses' => [
            ['status' => 503, 'body' => '{"retry":true}', 'fault_enabled' => true, 'fault_type' => 'truncated_body'], ['body' => '{"ok":true}'],
        ]]);
        $peek = $this->callTool('peek_next_response', $this->target($sequence));
        self::assertTrue($peek['ok'], json_encode($peek));
        self::assertSame('truncated_body', $peek['data']['response']['fault_type']);
        self::assertSame('{"retry":true}', $peek['data']['response']['body']);
        self::assertSame(0, EndpointCallState::count());
        $this->get('/sequence-fault', [RunRouting::HEADER => $this->run['run_id']])->assertStatus(503)->assertContent(substr('{"retry":true}', 0, intdiv(strlen('{"retry":true}'), 2)));
        $this->get('/sequence-fault', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent('{"ok":true}');
        self::assertSame(2, $this->callTool('get_calls', $this->target($sequence))['data']['sequence_position']);
        $rule = $this->create('/rule-fault', [], ['selection_mode' => 'rule', 'matching' => ['exclude_headers' => true], 'responses' => [
            ['body_mode' => 'template', 'body' => '{"id":"$string.uuid"}', 'fault_enabled' => true, 'fault_type' => 'malformed_body',
                'rule_conditions' => [['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'yes', 'priority' => 0]]],
            ['body' => '{"ok":true}', 'is_default' => true],
        ]]);
        $this->get('/rule-fault', [RunRouting::HEADER => $this->run['run_id'], 'X-Mode' => 'yes'])->assertOk()->assertContent('{"mockdeck_fault":');
        $this->get('/rule-fault', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent('{"ok":true}');
        self::assertSame(2, $this->callTool('get_calls', $this->target($rule))['data']['total_match_count']);
    }

    public function test_ordinary_faults_created_through_livewire_keep_their_behavior_and_configuration(): void
    {
        Livewire::test(EndpointForm::class)->set('rawCurl', 'curl http://localhost/dashboard-fault')->call('save')->assertHasNoErrors();
        $ordinary = MockEndpoint::sole();
        Livewire::test(ResponseManager::class, ['endpoint' => $ordinary])->set('body', '{"ui":true}')
            ->set('faultEnabled', true)->set('faultType', 'delay')->set('faultDelayMsMin', 20)->set('faultProbability', 100)->call('save')->assertHasNoErrors();
        $before = $ordinary->responses()->sole()->getRawOriginal();
        $runMock = $this->create('/dashboard-fault', ['fault_enabled' => true, 'fault_type' => 'malformed_body']);
        $this->get('/dashboard-fault', [RunRouting::HEADER => $this->run['run_id']])->assertContent('{"mockdeck_fault":');
        $start = hrtime(true);
        $this->get('/dashboard-fault')->assertOk()->assertContent('{"ui":true}');
        self::assertGreaterThanOrEqual(19, (hrtime(true) - $start) / 1_000_000);
        self::assertSame($before, $ordinary->responses()->sole()->getRawOriginal());
        self::assertNull($ordinary->test_run_id);
    }

    private function target(array $created): array
    {
        return [...$this->run, 'mock_id' => $created['data']['mock_id']];
    }

    private function create(string $path, array $fields = [], array $configuration = []): array
    {
        $result = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => $path,
            'configuration' => ['curl' => 'curl http://localhost'.$path, 'responses' => [['body' => '{"ok":true}', ...$fields]], ...$configuration]]);
        self::assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function callTool(string $tool, array $args): array
    {
        return app(McpDispatcher::class)->call($tool, $args, $this->owner);
    }
}
