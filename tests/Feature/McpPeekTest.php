<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\McpCredential;
use App\Models\Revision;
use App\Services\Environments\EnvironmentContext;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\TestRuns\RunRouting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class McpPeekTest extends TestCase
{
    use RefreshDatabase;

    private McpCredential $owner;

    private array $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = app(McpCredentialService::class)->generate('peek', ['read', 'write', 'verify', 'cleanup'])->credential;
        $this->run = ['run_id' => $this->callTool('start_test_run', ['label' => 'peek'])['data']['run_id']];
    }

    public function test_upcoming_response_is_correct_at_every_step_and_for_all_exhaustion_modes(): void
    {
        foreach (['repeat_last', 'loop', 'not_found'] as $policy) {
            $created = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => $policy, 'configuration' => [
                'request' => ['url' => 'http://localhost/peek-'.$policy], 'selection_mode' => 'sequence', 'sequence_on_exhaust' => $policy,
                'responses' => [['status' => 503, 'body' => 'one'], ['status' => 503, 'body' => 'two'], ['status' => 200, 'body' => 'three']],
            ]]);
            self::assertTrue($created['ok'], json_encode($created));
            $target = [...$this->run, 'mock_id' => $created['data']['mock_id']];
            foreach ([503, 503, 200] as $index => $status) {
                $peek = $this->callTool('peek_next_response', $target);
                self::assertTrue($peek['ok'], json_encode($peek));
                self::assertTrue($peek['data']['applicable']);
                self::assertSame($index, $peek['data']['sequence_position']);
                self::assertSame(($index + 1).' of 3', $peek['data']['position_label']);
                self::assertSame($created['data']['responses'][$index]['id'], $peek['data']['response']['id']);
                self::assertSame($status, $peek['data']['response']['status']);
                $this->get('/peek-'.$policy, [RunRouting::HEADER => $this->run['run_id']])->assertStatus($status)->assertContent(['one', 'two', 'three'][$index]);
            }
            $peek = $this->callTool('peek_next_response', $target)['data'];
            self::assertSame(3, $peek['sequence_position']);
            if ($policy === 'not_found') {
                self::assertSame('sequence_exhausted', $peek['reason']);
                self::assertNull($peek['response']);
                self::assertNull($peek['position_label']);
            } else {
                self::assertSame($policy === 'loop' ? 503 : 200, $peek['response']['status']);
                self::assertSame($policy === 'loop' ? '1 of 3' : '3 of 3', $peek['position_label']);
            }
        }
    }

    public function test_peek_is_byte_identical_and_issues_no_call_state_write_or_lazy_insert(): void
    {
        $created = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => 'bytes', 'configuration' => [
            'request' => ['url' => 'http://localhost/bytes'], 'selection_mode' => 'sequence', 'responses' => [['body' => 'a'], ['body' => 'b']],
        ]]);
        self::assertTrue($created['ok'], json_encode($created));
        $target = [...$this->run, 'mock_id' => $created['data']['mock_id']];
        $writes = [];
        $watch = true;
        DB::listen(function (QueryExecuted $query) use (&$writes, &$watch): void {
            if ($watch && str_contains($query->sql, 'endpoint_call_state') && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        self::assertSame(0, EndpointCallState::count());
        self::assertTrue($this->callTool('peek_next_response', $target)['ok']);
        self::assertSame(0, EndpointCallState::count());
        $watch = false;
        $this->get('/bytes', [RunRouting::HEADER => $this->run['run_id']])->assertOk();
        $before = json_encode(DB::table('endpoint_call_state')->orderBy('id')->get()->all());
        $revisions = Revision::count();
        $watch = true;
        self::assertTrue($this->callTool('peek_next_response', $target)['ok']);
        self::assertTrue($this->callTool('peek_next_response', $target)['ok']);
        self::assertSame($before, json_encode(DB::table('endpoint_call_state')->orderBy('id')->get()->all()));
        self::assertSame([], $writes);
        self::assertSame($revisions, Revision::count());
    }

    public function test_other_modes_are_not_applicable_and_read_permission_is_sufficient_only_for_owned_runs(): void
    {
        $targets = [];
        foreach (['weighted', 'rule'] as $mode) {
            $created = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => $mode, 'configuration' => [
                'request' => ['url' => 'http://localhost/'.$mode], 'selection_mode' => $mode,
                'responses' => [$mode === 'rule' ? ['is_default' => true] : ['body' => 'weighted']],
            ]]);
            self::assertTrue($created['ok'], json_encode($created));
            $targets[] = [...$this->run, 'mock_id' => $created['data']['mock_id']];
        }
        $this->owner->update(['can_write' => false, 'can_verify' => false, 'can_cleanup' => false]);
        foreach ($targets as $target) {
            $peek = $this->callTool('peek_next_response', $target);
            self::assertTrue($peek['ok'], json_encode($peek));
            self::assertFalse($peek['data']['applicable']);
            self::assertSame('not_applicable', $peek['data']['reason']);
            self::assertNull($peek['data']['response']);
            self::assertNull($peek['data']['sequence_position']);
        }
        self::assertSame(0, EndpointCallState::count());
        self::assertSame('FORBIDDEN', $this->callTool('get_calls', $targets[0])['error']['code']);
        $other = app(McpCredentialService::class)->generate('other reader', ['read'])->credential;
        self::assertSame('RUN_NOT_FOUND', app(McpDispatcher::class)->call('peek_next_response', $targets[0], $other)['error']['code']);
        $this->owner->update(['can_read' => false]);
        self::assertSame('FORBIDDEN', $this->callTool('peek_next_response', $targets[0])['error']['code']);
    }

    public function test_peek_returns_unrendered_template_configuration_with_secrets_redacted(): void
    {
        $template = '{"id":"$string.uuid","token":"private"}';
        $created = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => 'template', 'configuration' => [
            'request' => ['url' => 'http://localhost/peek-template'], 'selection_mode' => 'sequence',
            'responses' => [['body_mode' => 'template', 'body' => $template, 'headers' => ['Authorization' => 'Bearer confidential']]],
        ]]);
        self::assertTrue($created['ok'], json_encode($created));
        $peek = $this->callTool('peek_next_response', [...$this->run, 'mock_id' => $created['data']['mock_id']]);
        self::assertTrue($peek['ok'], json_encode($peek));
        self::assertStringContainsString('$string.uuid', $peek['data']['response']['body']);
        self::assertStringNotContainsString('private', json_encode($peek));
        self::assertStringNotContainsString('confidential', json_encode($peek));
        self::assertSame(0, EndpointCallState::count());
    }

    public function test_peek_uses_each_runs_bound_environment_and_cursor_without_switching_the_active_environment(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $bound = Environment::create(['name' => 'Bound peek', 'is_default' => false]);
        $other = ['run_id' => $this->callTool('start_test_run', ['label' => 'other peek', 'environment' => $bound->name])['data']['run_id']];
        $config = ['request' => ['url' => 'http://localhost/scoped-peek'], 'selection_mode' => 'sequence', 'responses' => [['body' => 'a'], ['body' => 'b']]];
        $first = $this->callTool('create_mock', [...$this->run, 'idempotency_key' => 'scoped', 'configuration' => $config]);
        $second = $this->callTool('create_mock', [...$other, 'idempotency_key' => 'scoped', 'configuration' => $config]);
        self::assertTrue($first['ok'], json_encode($first));
        self::assertTrue($second['ok'], json_encode($second));
        $this->get('/scoped-peek', [RunRouting::HEADER => $other['run_id']])->assertContent('a');
        $before = json_encode(DB::table('endpoint_call_state')->get()->all());
        $one = $this->callTool('peek_next_response', [...$this->run, 'mock_id' => $first['data']['mock_id']]);
        $two = $this->callTool('peek_next_response', [...$other, 'mock_id' => $second['data']['mock_id']]);
        self::assertSame('1 of 2', $one['data']['position_label']);
        self::assertSame('2 of 2', $two['data']['position_label']);
        self::assertSame($before, json_encode(DB::table('endpoint_call_state')->get()->all()));
        self::assertSame($bound->id, EndpointCallState::sole()->environment_id);
        self::assertSame($active->id, app(EnvironmentContext::class)->active()->id);
    }

    private function callTool(string $tool, array $args): array
    {
        return app(McpDispatcher::class)->call($tool, $args, $this->owner);
    }
}
