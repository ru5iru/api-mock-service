<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Revisions\RevisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_an_endpoint_three_times_creates_three_pre_state_revisions_with_diffs(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Original']);

        foreach (['First edit', 'Second edit', 'Third edit'] as $name) {
            Livewire::test(EndpointForm::class, ['endpoint' => $endpoint->fresh()])
                ->set('name', $name)
                ->call('save')
                ->assertHasNoErrors();
        }

        $revisions = $endpoint->revisions()->oldest('version_number')->get();
        self::assertCount(3, $revisions);
        self::assertSame(['Original', 'First edit', 'Second edit'], $revisions->pluck('snapshot.name')->all());

        $diff = app(RevisionManager::class)->diff($revisions[0], $revisions[1]);
        self::assertSame('name', $diff['changes'][0]['path']);
        self::assertSame('Original', $diff['changes'][0]['before']);
        self::assertSame('First edit', $diff['changes'][0]['after']);
    }

    public function test_no_op_save_does_not_create_a_revision(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Unchanged']);

        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(0, Revision::query()->count());
    }

    public function test_restore_applies_the_old_snapshot_and_appends_a_rollback_revision(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Original']);
        $manager = app(RevisionManager::class);
        $before = $manager->snapshot($endpoint);
        $endpoint->update(['name' => 'Current']);
        $target = $manager->recordIfChanged($endpoint, $before);

        self::assertNotNull($target);
        $restored = $manager->restore($target);

        self::assertSame('Original', $endpoint->fresh()->name);
        self::assertSame('rollback', $restored->source);
        self::assertSame($target->snapshot, $restored->snapshot);
        self::assertSame(2, $endpoint->revisions()->count());
    }

    public function test_compare_with_current_uses_the_same_diff_shape_and_includes_later_live_changes(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Before', 'priority' => 0]);
        $manager = app(RevisionManager::class);
        $before = $manager->snapshot($endpoint);
        $endpoint->update(['name' => 'After']);
        $revision = $manager->recordIfChanged($endpoint, $before);
        $endpoint->update(['priority' => 5]);

        $payload = $manager->diffCurrent($revision);
        self::assertSame(['entity_type', 'entity_id', 'from', 'to', 'changes'], array_keys($payload));
        self::assertSame('current', $payload['to']['kind']);
        self::assertContains('priority', array_column($payload['changes'], 'path'));

        $this->getJson("/api/endpoints/{$endpoint->id}/revisions/{$revision->id}/diff/current")
            ->assertOk()
            ->assertJsonPath('data.to.kind', 'current')
            ->assertJsonStructure(['data' => ['changes' => [['path', 'type', 'before', 'after', 'renderer']]]]);
    }

    public function test_update_by_uuid_import_groups_pre_states_and_undo_restores_every_entity(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'name' => 'Before import',
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
        ]);
        $response = MockResponse::factory()->for($endpoint, 'endpoint')->create([
            'uuid' => '018f37a0-5100-7b64-9df0-9a1f5f90795c',
            'body' => '{"state":"before"}',
        ]);
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true, 64, JSON_THROW_ON_ERROR);
        $document['endpoints'][0]['name'] = 'After import';
        $document['endpoints'][0]['responses'][0]['body'] = '{"state":"after"}';
        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, ImportMode::Upsert);
        self::assertSame(2, $plan->counts['revision_snapshots']);
        $summary = $importer->apply($plan->token, $plan->digest, true);

        self::assertNotNull($summary->importBatchId);
        self::assertSame(2, $summary->revisionSnapshots);
        self::assertSame(2, Revision::query()->where('import_batch_id', $summary->importBatchId)->count());
        self::assertSame('After import', $endpoint->fresh()->name);
        self::assertSame('{"state":"after"}', $response->fresh()->body);

        $this->getJson('/api/imports/'.$summary->importBatchId)
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
        $this->postJson('/api/imports/'.$summary->importBatchId.'/undo')
            ->assertOk()
            ->assertJsonPath('data.restored', 2);

        self::assertSame('Before import', $endpoint->fresh()->name);
        self::assertSame('{"state":"before"}', $response->fresh()->body);
        self::assertSame(2, Revision::query()->where('source', 'rollback')->count());
    }

    public function test_no_op_update_by_uuid_import_creates_no_revision_or_undo_batch(): void
    {
        MockEndpoint::factory()->create([
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
        ]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, ImportMode::Upsert);

        self::assertSame(0, $plan->counts['revision_snapshots']);
        $summary = $importer->apply($plan->token, $plan->digest, true);

        self::assertSame(0, $summary->revisionSnapshots);
        self::assertNull($summary->importBatchId);
        self::assertSame(0, Revision::query()->count());
    }

    public function test_revision_api_lists_newest_first_and_restores_without_mutating_history(): void
    {
        $response = MockResponse::factory()->create(['status_code' => 200]);
        $manager = app(RevisionManager::class);
        foreach ([201, 202] as $status) {
            $before = $manager->snapshot($response);
            $response->update(['status_code' => $status]);
            $manager->recordIfChanged($response, $before);
        }
        $oldest = $response->revisions()->oldest('version_number')->firstOrFail();

        $this->getJson("/api/responses/{$response->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.0.version_number', 2)
            ->assertJsonStructure(['data' => [['id', 'version_number', 'source', 'summary', 'created_at']]]);

        $this->postJson("/api/responses/{$response->id}/revisions/{$oldest->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.source', 'rollback');

        self::assertSame(200, $response->fresh()->status_code);
        self::assertSame(3, $response->revisions()->count());
        self::assertSame(200, $oldest->fresh()->snapshot['status_code']);
    }
}
