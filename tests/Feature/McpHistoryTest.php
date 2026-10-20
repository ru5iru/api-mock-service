<?php

namespace Tests\Feature;

use App\Livewire\Admin\RevisionHistory;
use App\Models\Revision;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class McpHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_history_uses_existing_component_is_read_only_and_survives_cleanup(): void
    {
        $owner = app(McpCredentialService::class)->generate('history', ['read', 'write', 'cleanup'])->credential;
        $call = fn ($tool, $args) => app(McpDispatcher::class)->call($tool, $args, $owner);
        $run = ['run_id' => $call('start_test_run', ['label' => 'History fixture'])['data']['run_id']];
        $mock = $call('create_mock', [...$run, 'idempotency_key' => 'history', 'configuration' => ['curl' => 'curl http://localhost/history', 'responses' => [['body' => 'history']]]]);
        self::assertTrue($mock['ok'], json_encode($mock));
        $row = Revision::query()->firstOrFail();
        $this->get('/dashboard/test-runs/'.$run['run_id'].'/history')->assertOk()->assertSee('History fixture')->assertSee('Version 1')->assertDontSee('>Restore</button>', false);
        Livewire::test(RevisionHistory::class, ['entityType' => 'endpoint', 'entityId' => $row->entity_id, 'runId' => $row->test_run_id])->call('compareWithCurrent', $row->id)->assertSee('No structural differences')->call('restore', $row->id)->assertForbidden();
        $call('finish_test_run', [...$run, 'cleanup' => 'delete_mocks']);
        $this->get('/dashboard/test-runs/'.$run['run_id'].'/history')->assertOk()->assertSee('Version 2')->assertDontSee('>Compare with current</button>', false);
    }
}
