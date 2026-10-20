<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Mcp\McpCredentialService;
use App\Services\Revisions\RevisionManager;
use App\Services\TestRuns\TestRunService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TestRunOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_binding_visibility_and_runtime_only_revision_contract(): void
    {
        $owner = app(McpCredentialService::class)->generate('owner', ['write', 'cleanup'])->credential;
        $environment = Environment::query()->firstOrFail();
        $run = app(TestRunService::class)->create($owner, $environment, 'contract');
        self::assertTrue(Str::isUuid($run->public_id));
        $endpoint = $run->endpoints()->save(MockEndpoint::factory()->make());
        $response = $endpoint->responses()->create(['body' => 'run']);
        self::assertSame(0, MockEndpoint::query()->count());
        self::assertSame(0, MockResponse::query()->count());
        self::assertSame($response->id, $endpoint->responses()->sole()->id);
        self::assertNull(app(RevisionManager::class)->recordIfChanged($endpoint, []));
        self::assertNull(app(RevisionManager::class)->recordIfChanged($response, []));
        app(TestRunService::class)->finish($owner, $run->public_id, true);
        self::assertSame('finished', $run->refresh()->status);
        self::assertNotNull($run->finished_at);
        self::assertSame(0, $run->endpoints()->count());
    }

    public function test_read_only_credential_cannot_create_a_run(): void
    {
        $owner = app(McpCredentialService::class)->generate('reader', ['read'])->credential;
        $this->expectException(AuthorizationException::class);
        app(TestRunService::class)->create($owner, Environment::query()->firstOrFail(), 'no');
    }

    public function test_null_run_scope_remains_unique(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $values = ['endpoint_id' => $endpoint->id, 'environment_id' => Environment::query()->firstOrFail()->id];
        EndpointCallState::query()->create($values);
        $this->expectException(QueryException::class);
        EndpointCallState::query()->create($values);
    }
}
