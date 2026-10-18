<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class McpMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_migrations_round_trip_without_changing_existing_config_tokens_or_runtime_state(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'untouched']);
        $environment = app(EnvironmentContext::class)->active();
        $endpoint->callStates()->create(['environment_id' => $environment->id, 'sequence_position' => 3, 'total_match_count' => 7]);
        ApiToken::query()->create(['name' => 'verification', 'token_hash' => hash('sha256', 'existing-token')]);
        $tables = ['mock_endpoints', 'mock_responses', 'endpoint_call_state', 'api_tokens'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $credentials = require database_path('migrations/2026_10_18_000001_create_mcp_credentials.php');
        $audits = require database_path('migrations/2026_10_18_000002_create_mcp_invocations.php');
        $audits->down();
        $credentials->down();
        $credentials->up();
        $audits->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], DB::table($table)->get()->toJson());
        }
        self::assertSame('untouched', $response->fresh()->body);
        self::assertSame(0, DB::table('mcp_credentials')->count());
        self::assertSame(0, DB::table('mcp_invocations')->count());
    }
}
