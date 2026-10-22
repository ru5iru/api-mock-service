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
use App\Services\TestRuns\RunRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class McpSelectionTest extends TestCase
{
    use RefreshDatabase;

    private McpCredential $owner;

    private array $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = app(McpCredentialService::class)->generate('selection', ['read', 'write', 'verify', 'cleanup'])->credential;
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'selection'])['data']['run_id']];
    }

    public function test_sequence_creation_serves_in_order_and_honors_every_existing_exhaustion_policy(): void
    {
        foreach (['repeat_last' => 200, 'loop' => 503, 'not_found' => 404] as $policy => $fourthStatus) {
            $created = $this->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => $policy,
                'responses' => [['status' => 503, 'body' => 'second', 'sequence_order' => 1], ['status' => 503, 'body' => 'first', 'sequence_order' => 0], ['status' => 200, 'body' => 'third', 'sequence_order' => 2]]], '/sequence-'.$policy);
            self::assertSame('sequence', $created['data']['selection_mode']);
            foreach ([[503, 'first'], [503, 'second'], [200, 'third']] as [$status, $body]) {
                $this->get('/sequence-'.$policy, [RunRouting::HEADER => $this->run['run_id']])->assertStatus($status)->assertContent($body);
            }
            $this->get(RunRouting::PREFIX.$this->run['run_id'].'/sequence-'.$policy)->assertStatus($fourthStatus);
            self::assertSame(4, EndpointCallState::query()->where('endpoint_id', $this->endpoint($created)->id)->sole()->sequence_position);
        }
    }

    public function test_rules_use_shared_predicates_priorities_and_exactly_one_default(): void
    {
        $created = $this->create(['selection_mode' => 'rule', 'matching' => ['exclude_headers' => true], 'responses' => [
            ['body' => 'later', 'rule_conditions' => [$this->condition('later', 9)]],
            ['body' => 'first', 'rule_conditions' => [$this->condition('yes', 0)]],
            ['body' => 'fallback', 'is_default' => true],
        ]], '/rules');
        $this->get('/rules', [RunRouting::HEADER => $this->run['run_id'], 'X-Mode' => 'yes'])->assertOk()->assertContent('first');
        $this->get('/rules', [RunRouting::HEADER => $this->run['run_id'], 'X-Mode' => 'later'])->assertOk()->assertContent('later');
        $this->get('/rules', [RunRouting::HEADER => $this->run['run_id'], 'X-Mode' => 'no'])->assertOk()->assertContent('fallback');
        self::assertCount(1, $created['data']['responses'][1]['rule_conditions']);
        self::assertSame(1, $this->endpoint($created)->responses()->where('is_default', true)->count());
    }

    public function test_dry_run_and_create_reject_identical_invalid_selection_configurations_without_partial_writes(): void
    {
        $cases = [
            [['responses' => []], 'arguments.configuration.responses'],
            [['selection_mode' => 'rule', 'responses' => [['body' => 'a']]], 'is_default'],
            [['selection_mode' => 'rule', 'responses' => [['is_default' => true], ['is_default' => true]]], 'is_default'],
            [['selection_mode' => 'sequence', 'responses' => [['sequence_order' => 0], ['sequence_order' => 0]]], 'sequence_order'],
            [['selection_mode' => 'sequence', 'responses' => [['sequence_order' => 2]]], 'sequence_order'],
            [['responses' => [['sequence_order' => 0]]], 'responses.0.sequence_order'],
            [['responses' => [['rule_conditions' => []]]], 'responses.0.rule_conditions'],
            [['responses' => [['is_default' => false]]], 'responses.0.is_default'],
            [['sequence_on_exhaust' => 'loop', 'responses' => [[]]], 'sequence_on_exhaust'],
            [['selection_mode' => 'rule', 'responses' => [['is_default' => true], ['rule_conditions' => [['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'regex', 'value' => '[', 'priority' => 0]]]]], 'responseRules.1.0'],
        ];
        foreach ($cases as $index => [$proposal, $field]) {
            $config = ['curl' => 'curl http://localhost/invalid-'.$index, ...$proposal];
            $dry = $this->callTool('validate_mock', ['configuration' => $config]);
            $write = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => 'invalid-'.$index, 'configuration' => $config]);
            self::assertSame('VALIDATION_ERROR', $dry['error']['code'], json_encode($dry));
            self::assertSame($dry['error']['details'], $write['error']['details']);
            self::assertArrayHasKey($field, $dry['error']['details']['errors']);
        }
        self::assertSame(0, MockEndpoint::withoutGlobalScope('ordinary')->count());
        self::assertSame(0, MockResponse::withoutGlobalScope('ordinary')->count());
        self::assertSame(0, Revision::count());
    }

    public function test_updates_validate_final_pool_atomically_and_preserve_omitted_fields_and_call_state(): void
    {
        $created = $this->create(['selection_mode' => 'rule', 'responses' => [['body' => 'a', 'is_default' => true], ['body' => 'b']]], '/swap');
        $target = [...$this->run, 'mock_id' => $created['data']['mock_id']];
        $this->get('/swap', [RunRouting::HEADER => $this->run['run_id']])->assertContent('a');
        $state = EndpointCallState::sole()->getRawOriginal();
        $changes = ['responses' => ['update' => [['id' => $created['data']['responses'][0]['id'], 'is_default' => false], ['id' => $created['data']['responses'][1]['id'], 'is_default' => true]]]];
        self::assertTrue($this->callTool('validate_mock', [...$target, 'changes' => $changes])['ok']);
        $updated = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => $changes]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame('rule', $updated['data']['selection_mode']);
        self::assertSame(2, $updated['data']['version']);
        self::assertSame($state, EndpointCallState::sole()->getRawOriginal());
        $this->get('/swap', [RunRouting::HEADER => $this->run['run_id']])->assertContent('b');
        $invalid = ['responses' => ['remove' => [$created['data']['responses'][1]['id']]]];
        $dry = $this->callTool('validate_mock', [...$target, 'changes' => $invalid]);
        $write = $this->callTool('update_mock', [...$target, 'expected_version' => 2, 'changes' => $invalid]);
        self::assertSame($dry['error']['details'], $write['error']['details']);
        self::assertArrayHasKey('is_default', $write['error']['details']['errors']);
        self::assertSame(2, $this->endpoint($created)->responses()->count());
        self::assertSame(2, $this->callTool('get_mock', $target)['data']['version']);
    }

    public function test_sequence_reordering_and_add_remove_share_dry_run_validation(): void
    {
        $created = $this->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop', 'responses' => [['body' => 'a'], ['body' => 'b'], ['body' => 'c']]], '/reorder');
        $target = [...$this->run, 'mock_id' => $created['data']['mock_id']];
        $responses = $created['data']['responses'];
        $changes = ['responses' => ['remove' => [$responses[1]['id']], 'update' => [['id' => $responses[0]['id'], 'sequence_order' => 1], ['id' => $responses[2]['id'], 'sequence_order' => 0]], 'add' => [['body' => 'd', 'sequence_order' => 2]]]];
        self::assertTrue($this->callTool('validate_mock', [...$target, 'changes' => $changes])['ok']);
        $updated = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => $changes]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame('loop', $updated['data']['sequence_on_exhaust']);
        foreach (['c', 'a', 'd', 'c'] as $body) {
            $this->get('/reorder', [RunRouting::HEADER => $this->run['run_id']])->assertOk()->assertContent($body);
        }
        $noOp = $this->callTool('update_mock', [...$target, 'expected_version' => 2, 'changes' => []]);
        self::assertTrue($noOp['ok'], json_encode($noOp));
        self::assertSame(2, $noOp['data']['version']);
    }

    public function test_omitting_selection_fields_keeps_weighted_defaults_and_existing_patches(): void
    {
        $created = $this->create(['responses' => [['status' => 201, 'body' => 'weighted']]], '/weighted');
        self::assertSame('weighted', $created['data']['selection_mode']);
        self::assertNull($created['data']['responses'][0]['sequence_order']);
        self::assertFalse($created['data']['responses'][0]['is_default']);
        self::assertSame([], $created['data']['responses'][0]['rule_conditions']);
        $target = [...$this->run, 'mock_id' => $created['data']['mock_id']];
        $before = $this->endpoint($created)->getRawOriginal();
        $noOp = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => []]);
        self::assertTrue($noOp['ok'], json_encode($noOp));
        self::assertSame(1, $noOp['data']['version']);
        self::assertSame($before, $this->endpoint($created)->getRawOriginal());
        $updated = $this->callTool('update_mock', [...$target, 'expected_version' => 1, 'changes' => ['responses' => ['update' => [['id' => $created['data']['responses'][0]['id'], 'body' => 'patched']]]]]);
        self::assertTrue($updated['ok'], json_encode($updated));
        self::assertSame('weighted', $updated['data']['selection_mode']);
        $this->get('/weighted', [RunRouting::HEADER => $this->run['run_id']])->assertStatus(201)->assertContent('patched');
        self::assertSame(0, EndpointCallState::sole()->sequence_position);
    }

    public function test_rule_values_are_redacted_by_the_existing_sensitive_field_policy(): void
    {
        $created = $this->create(['selection_mode' => 'rule', 'responses' => [['body' => 'sensitive', 'rule_conditions' => [[...$this->condition('very-private-value', 0), 'field_name' => 'Authorization']]], ['body' => 'fallback', 'is_default' => true]]], '/redacted-rules');
        self::assertStringNotContainsString('very-private-value', json_encode($created));
        self::assertSame('[REDACTED]', $created['data']['responses'][0]['rule_conditions'][0]['value']);
        self::assertStringNotContainsString('very-private-value', json_encode(Revision::all()));
    }

    public function test_existing_condition_grammar_accepts_exists_without_a_value_and_null_values(): void
    {
        foreach ([null, 'omit'] as $value) {
            $rule = [...$this->condition('', 0), 'operator' => 'exists', 'value' => null];
            if ($value === 'omit') {
                unset($rule['value']);
            }
            $created = $this->create(['selection_mode' => 'rule', 'matching' => ['exclude_headers' => true], 'responses' => [['body' => 'present', 'rule_conditions' => [$rule]], ['body' => 'fallback', 'is_default' => true]]], '/exists-'.($value ?? 'null'));
            $this->get('/exists-'.($value ?? 'null'), [RunRouting::HEADER => $this->run['run_id'], 'X-Mode' => 'anything'])->assertContent('present');
        }
    }

    public function test_ordinary_mocks_created_and_configured_through_livewire_keep_their_selection_and_routing(): void
    {
        foreach (['sequence', 'rule'] as $mode) {
            $path = '/ordinary-'.$mode;
            Livewire::test(EndpointForm::class)->set('rawCurl', 'curl http://localhost'.$path)->set('excludeHeaders', true)->call('save')->assertHasNoErrors();
            $ordinary = MockEndpoint::query()->latest('id')->firstOrFail();
            foreach ([[503, 'retry'], [200, 'done']] as [$status, $body]) {
                Livewire::test(ResponseManager::class, ['endpoint' => $ordinary])->set('statusCode', $status)->set('body', $body)->call('save')->assertHasNoErrors();
            }
            $responses = $ordinary->responses()->get();
            $editor = Livewire::test(ResponseManager::class, ['endpoint' => $ordinary])->call('setSelectionMode', $mode);
            if ($mode === 'rule') {
                $editor->call('setFallback', $responses[1]->id)->call('addRule', $responses[0]->id)
                    ->set('responseRules.'.$responses[0]->id.'.0.field_name', 'X-Mode')
                    ->set('responseRules.'.$responses[0]->id.'.0.value', 'yes');
            }
            $editor->call('saveSelection')->assertHasNoErrors();
            self::assertSame($mode, $ordinary->fresh()->selection_mode);
            $before = $ordinary->fresh()->getRawOriginal();
            $mcp = $this->create(['matching' => ['exclude_headers' => true], 'responses' => [['status' => 202, 'body' => 'isolated MCP']]], $path);
            $this->get($path, [RunRouting::HEADER => $this->run['run_id']])->assertStatus(202)->assertContent('isolated MCP');
            $this->get($path, $mode === 'rule' ? ['X-Mode' => 'yes'] : [])->assertStatus(503)->assertContent('retry');
            $this->get($path)->assertOk()->assertContent('done');
            self::assertSame($before, $ordinary->fresh()->getRawOriginal());
            self::assertNull($ordinary->test_run_id);
            self::assertSame($mode === 'sequence' ? 2 : 0, EndpointCallState::query()->where('endpoint_id', $ordinary->id)->sole()->sequence_position);
            $denied = $this->callTool('get_mock', [...$this->run, 'mock_id' => $ordinary->uuid]);
            self::assertSame('NOT_FOUND', $denied['error']['code']);
        }
    }

    private function condition(string $value, int $priority): array
    {
        return ['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => $value, 'priority' => $priority];
    }

    private function create(array $configuration, string $path): array
    {
        $result = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => $path, 'configuration' => ['curl' => 'curl http://localhost'.$path, ...$configuration]]);
        self::assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function endpoint(array $created): MockEndpoint
    {
        return MockEndpoint::withoutGlobalScope('ordinary')->where('uuid', $created['data']['mock_id'])->firstOrFail();
    }

    private function callTool(string $tool, array $args): array
    {
        return app(McpDispatcher::class)->call($tool, $args, $this->owner);
    }
}
