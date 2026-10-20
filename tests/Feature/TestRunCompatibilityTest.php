<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Mcp\McpCredentialService;
use App\Services\Revisions\RevisionManager;
use App\Services\TestRuns\TestRunService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TestRunCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_existing_canonical_hash_version_and_export_bytes(): void
    {
        $this->freezeTime();
        $endpoint = MockEndpoint::factory()->create();
        $endpoint->responses()->create(['body' => 'ordinary']);
        $migration = require database_path('migrations/2026_10_18_000003_add_test_run_isolation.php');
        $migration->down();
        $before = DB::table('mock_endpoints')->where('id', $endpoint->id)->first();
        $migration->up();
        self::assertSame($before->normalized_curl, $endpoint->fresh()->normalized_curl);
        self::assertSame($before->curl_hash, $endpoint->fresh()->curl_hash);
        self::assertSame($before->signature_version, $endpoint->fresh()->signature_version);
        $export = app(ConfigExporter::class)->export()->toJson();
        $owner = app(McpCredentialService::class)->generate('owner', ['write'])->credential;
        $run = app(TestRunService::class)->create($owner, Environment::query()->firstOrFail(), 'ephemeral');
        $runtime = $run->endpoints()->save(MockEndpoint::factory()->make());
        $response = $runtime->responses()->create(['body' => 'private runtime body']);
        EndpointCallState::query()->create(['endpoint_id' => $runtime->id, 'environment_id' => $run->environment_id, 'test_run_id' => $run->id]);
        self::assertSame($export, app(ConfigExporter::class)->export()->toJson());
        self::assertSame([], app(ConfigExporter::class)->export([$runtime->uuid])->endpoints());
        $this->expectException(\LogicException::class);
        app(RevisionManager::class)->record($response, app(RevisionManager::class)->snapshot($response));
    }

    public function test_upgrade_reports_reserved_path_collision_before_altering_schema(): void
    {
        $endpoint = MockEndpoint::factory()->create(['normalized_curl' => "GET\n/%6dcp/tools\n\n", 'raw_curl' => 'curl http://localhost/%6dcp/tools']);
        $migration = require database_path('migrations/2026_10_18_000003_add_test_run_isolation.php');
        $migration->down();
        try {
            $migration->up();
            self::fail('A reserved operator route must not silently disappear on upgrade.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Endpoint '.$endpoint->id, $exception->getMessage());
            self::assertFalse(Schema::hasTable('test_runs'));
            self::assertFalse(Schema::hasColumn('mock_endpoints', 'test_run_id'));
        } finally {
            DB::table('mock_endpoints')->where('id', $endpoint->id)->update(['normalized_curl' => "GET\n/renamed\n\n", 'raw_curl' => 'curl http://localhost/renamed']);
            $migration->up();
        }
    }

    public function test_old_verification_token_cannot_read_or_reset_run_state(): void
    {
        $owner = app(McpCredentialService::class)->generate('owner', ['write'])->credential;
        $environment = Environment::query()->firstOrFail();
        $run = app(TestRunService::class)->create($owner, $environment, 'private');
        $ordinary = MockEndpoint::factory()->create();
        $runtime = $run->endpoints()->save(MockEndpoint::factory()->make());
        foreach ([$ordinary, $runtime] as $endpoint) {
            EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => $environment->id,
                'test_run_id' => $endpoint->test_run_id, 'total_match_count' => 7, 'sequence_position' => 7]);
        }
        $token = 'mdv_'.str_repeat('b', 64);
        ApiToken::query()->create(['name' => 'legacy verifier', 'token_hash' => hash('sha256', $token)]);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/verify/endpoints/'.$runtime->uuid.'/calls?environment='.$environment->name, $headers)->assertNotFound();
        $this->postJson('/api/v1/verify/reset-all?environment='.$environment->name, [], $headers)->assertOk()->assertJsonPath('state_rows_reset', 1);
        self::assertSame(0, EndpointCallState::query()->where('endpoint_id', $ordinary->id)->sole()->total_match_count);
        self::assertSame(7, EndpointCallState::query()->where('endpoint_id', $runtime->id)->sole()->total_match_count);
    }

    public function test_external_import_preview_blocks_reserved_routes_before_writing(): void
    {
        $json = json_encode(['info' => ['name' => 'Collision', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [['name' => 'reserved', 'request' => ['method' => 'GET', 'url' => ['raw' => 'https://example.test/mcp/tools', 'path' => ['mcp', 'tools']]]]]]);
        $plan = app(MappedImportService::class)->preview(
            app(PostmanMapper::class), $json, ImportMode::CreateOnly,
        );
        self::assertStringContainsString('reserved', implode(' ', $plan->errors));
        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_another_owner_cannot_finish_or_delete_a_run(): void
    {
        $owner = app(McpCredentialService::class)->generate('owner', ['write'])->credential;
        $other = app(McpCredentialService::class)->generate('other', ['cleanup'])->credential;
        $run = app(TestRunService::class)->create($owner, Environment::query()->firstOrFail(), 'private');
        $this->expectException(ModelNotFoundException::class);
        app(TestRunService::class)->finish($other, $run->public_id, true);
    }
}
