<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Livewire\Admin\EndpointIndex;
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

    public function test_endpoint_list_offers_a_mock_curl_copy_button(): void
    {
        config(['app.url' => 'http://localhost:18473']);
        $endpoint = MockEndpoint::factory()->create([
            'raw_curl' => "curl 'https://api.example.test/v1/items?limit=10'",
        ]);

        Livewire::test(EndpointIndex::class)
            ->assertSee('Copy mock curl')
            ->assertSee("curl 'http://localhost:18473/v1/items?limit=10'", escape: false);
    }
}
