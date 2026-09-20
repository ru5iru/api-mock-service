<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\EndpointIndex;
use App\Livewire\Admin\LogViewer;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_available_through_the_access_middleware_alias(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Request endpoints')
            ->assertSee('New endpoint');
    }

    public function test_response_manager_creates_updates_and_deletes_a_response(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $component = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->set('statusCode', 202)
            ->set('headersJson', '{"Content-Type":"application/json","X-Test":"yes"}')
            ->set('body', '{"queued":true}')
            ->set('delayMs', 25)
            ->set('weight', 3)
            ->call('save')
            ->assertHasNoErrors();

        $response = $endpoint->responses()->sole();
        self::assertSame(202, $response->status_code);
        self::assertSame(3, $response->weight);

        $component->call('edit', $response->id)
            ->set('statusCode', 204)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(204, $response->fresh()->status_code);

        $component->call('delete', $response->id);
        $this->assertDatabaseMissing('mock_responses', ['id' => $response->id]);
    }

    public function test_endpoint_form_rejects_an_exact_duplicate_signature(): void
    {
        $normalized = "GET\n/duplicate\n\n";
        MockEndpoint::factory()->create([
            'name' => 'Existing endpoint',
            'raw_curl' => "curl 'https://api.example.test/duplicate'",
            'normalized_curl' => $normalized,
            'curl_hash' => hash('sha256', $normalized),
            'exclude_headers' => true,
        ]);

        Livewire::test(EndpointForm::class)
            ->set('rawCurl', "curl 'https://another-origin.example/duplicate'")
            ->set('excludeHeaders', true)
            ->call('save')
            ->assertHasErrors(['rawCurl']);

        self::assertSame(1, MockEndpoint::query()->count());
    }

    public function test_endpoint_list_filters_and_toggles_runtime_state(): void
    {
        $getEndpoint = MockEndpoint::factory()->create([
            'name' => 'Enabled GET endpoint',
            'method' => 'GET',
            'enabled' => true,
        ]);
        MockEndpoint::factory()->create([
            'name' => 'Disabled POST endpoint',
            'method' => 'POST',
            'enabled' => false,
        ]);

        Livewire::test(EndpointIndex::class)
            ->assertSee('Enabled GET endpoint')
            ->assertSee('Disabled POST endpoint')
            ->set('method', 'GET')
            ->assertSee('Enabled GET endpoint')
            ->assertDontSee('Disabled POST endpoint')
            ->set('method', '')
            ->set('state', 'disabled')
            ->assertDontSee('Enabled GET endpoint')
            ->assertSee('Disabled POST endpoint')
            ->set('state', 'all')
            ->set('search', 'Enabled')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('method', '')
            ->assertSet('state', 'all')
            ->call('toggleEnabled', $getEndpoint->id)
            ->assertSee('Enable');

        self::assertFalse($getEndpoint->fresh()->enabled);
    }

    public function test_request_log_filters_can_be_cleared(): void
    {
        Livewire::test(LogViewer::class)
            ->set('method', 'POST')
            ->set('match', 'missed')
            ->set('status', '4xx')
            ->call('clearFilters')
            ->assertSet('method', '')
            ->assertSet('match', 'all')
            ->assertSet('status', 'all');
    }

    public function test_endpoint_list_offers_a_mock_host_curl_copy_button(): void
    {
        config(['app.url' => 'http://localhost:18473']);
        MockEndpoint::factory()->create([
            'name' => 'Copyable endpoint',
            'raw_curl' => "curl 'https://api.example.test/v1/items?limit=10'",
        ]);

        Livewire::test(EndpointIndex::class)
            ->assertSee('Copy mock curl')
            ->assertSee("curl 'http://localhost:18473/v1/items?limit=10'");
    }

    public function test_endpoint_list_survives_an_invalid_legacy_curl(): void
    {
        MockEndpoint::factory()->create([
            'name' => 'Broken legacy endpoint',
            'raw_curl' => 'curl --unknown-option',
        ]);

        Livewire::test(EndpointIndex::class)
            ->assertSee('Broken legacy endpoint')
            ->assertSee('Copy unavailable');
    }
}
