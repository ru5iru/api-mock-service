<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointIndex;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Mcp\McpCredentialService;
use App\Services\TestRuns\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class WorkbenchShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_dashboard_destination_has_shared_navigation_and_preferences(): void
    {
        foreach (['/dashboard', '/dashboard/endpoints/create', '/dashboard/logs', '/dashboard/logs?type=callbacks', '/dashboard/config', '/dashboard/environments', '/dashboard/docs'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('workbench-rail', false)
                ->assertSee('id="workbench-context"', false)
                ->assertSee('aria-controls="workbench-context"', false)
                ->assertSee('data-single-key-shortcuts', false)
                ->assertSee('Skip to main content');
        }
    }

    public function test_explorer_is_paginated_searchable_and_keeps_its_active_identity(): void
    {
        $first = MockEndpoint::factory()->create(['name' => 'Target endpoint']);
        MockEndpoint::factory()->count(17)->create(['name' => 'Other endpoint']);
        Livewire::test(EndpointIndex::class, ['explorer' => true, 'currentEndpointId' => $first->id])
            ->assertViewIs('livewire.admin.endpoint-explorer')
            ->assertViewHas('endpoints', fn ($items) => $items->count() === 15 && $items->total() === 18)
            ->set('search', 'Target endpoint')
            ->assertSee('Target endpoint')->assertDontSee('Other endpoint')
            ->assertSee('aria-current="page"', false);
    }

    public function test_explorer_does_not_expose_run_mocks_or_request_credentials(): void
    {
        $owner = app(McpCredentialService::class)->generate('owner', ['write'])->credential;
        $run = app(TestRunService::class)->create($owner, Environment::query()->firstOrFail(), 'private');
        $run->endpoints()->save(MockEndpoint::factory()->make(['name' => 'Run-only endpoint']));
        MockEndpoint::factory()->create(['name' => 'Ordinary endpoint', 'raw_curl' => "curl 'https://example.test/private?token=private-query' -H 'Authorization: Bearer private-header'"]);
        Livewire::test(EndpointIndex::class, ['explorer' => true])
            ->assertSee('Ordinary endpoint')->assertDontSee('Run-only endpoint')
            ->assertDontSee('private-query')->assertDontSee('private-header');
    }
}
