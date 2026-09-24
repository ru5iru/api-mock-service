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
            ->assertSee('Endpoints')
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

    public function test_endpoint_form_derives_a_name_and_routes_new_endpoint_to_responses(): void
    {
        Livewire::test(EndpointForm::class)
            ->set('name', '')
            ->set('rawCurl', "curl --request POST 'https://api.example.test/v1/orders?expand=items' --json '{\"id\":1}'")
            ->call('save')
            ->assertHasNoErrors();

        $endpoint = MockEndpoint::query()->sole();
        self::assertSame('POST /v1/orders', $endpoint->name);
        self::assertSame(0, $endpoint->responses()->count());
    }

    public function test_sample_curl_must_be_replaced_before_endpoint_creation(): void
    {
        Livewire::test(EndpointForm::class)
            ->call('loadExample')
            ->call('save')
            ->assertHasErrors(['rawCurl']);

        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_ignoring_all_headers_visibly_includes_cookie_and_auth_exclusions(): void
    {
        Livewire::test(EndpointForm::class)
            ->set('excludeCookies', false)
            ->set('excludeAuth', false)
            ->set('excludeHeaders', true)
            ->assertSet('excludeCookies', true)
            ->assertSet('excludeAuth', true);
    }

    public function test_endpoint_form_can_mask_detected_request_secrets(): void
    {
        Livewire::test(EndpointForm::class)
            ->set('rawCurl', "curl 'https://api.example.test/users?api_key=secret-key' -H 'Authorization: Bearer live-token'")
            ->call('maskSecrets')
            ->assertSet('rawCurl', "curl 'https://api.example.test/users?api_key=REPLACE_ME' -H 'Authorization: REPLACE_ME'");
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
        Livewire::test(LogViewer::class, ['full' => true])
            ->set('method', 'POST')
            ->set('match', 'none')
            ->set('status', '4xx')
            ->set('search', '/users')
            ->set('endpoint', '12')
            ->set('unmatchedOnly', true)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('method', '')
            ->assertSet('match', 'all')
            ->assertSet('status', 'all')
            ->assertSet('endpoint', 'all')
            ->assertSet('unmatchedOnly', false);
    }

    public function test_endpoint_without_name_uses_method_and_path_and_warns_when_no_response_exists(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'name' => null,
            'method' => 'POST',
            'normalized_curl' => "POST\n/v1/orders?expand=items\ncontent-type:application/json\n\n{\"ok\":true}",
        ]);

        self::assertSame('POST /v1/orders', $endpoint->displayName());

        Livewire::test(EndpointIndex::class)
            ->assertSee('POST /v1/orders')
            ->assertSee('1 header')
            ->assertSee('No responses – requests will fail');
    }

    public function test_duplicate_endpoint_is_created_disabled_with_its_responses(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Orders']);
        $endpoint->responses()->create([
            'status_code' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"created":true}',
            'delay_ms' => 0,
            'weight' => 1,
        ]);

        Livewire::test(EndpointIndex::class)->call('duplicate', $endpoint->id)->assertHasNoErrors();

        $copy = MockEndpoint::query()->where('id', '!=', $endpoint->id)->sole();
        self::assertSame('Copy of Orders', $copy->name);
        self::assertFalse($copy->enabled);
        self::assertSame(1, $copy->responses()->count());
    }

    public function test_bulk_endpoint_state_actions_clear_the_selection(): void
    {
        $endpoints = MockEndpoint::factory()->count(2)->create(['enabled' => false]);

        Livewire::test(EndpointIndex::class)
            ->set('selected', $endpoints->pluck('id')->all())
            ->call('bulkSetEnabled', true)
            ->assertSet('selected', []);

        self::assertSame(2, MockEndpoint::query()->where('enabled', true)->count());
    }

    public function test_bulk_action_bar_only_appears_after_selection(): void
    {
        $endpoint = MockEndpoint::factory()->create();

        Livewire::test(EndpointIndex::class)
            ->assertDontSee('selection-bar', false)
            ->set('selected', [$endpoint->id])
            ->assertSee('selection-bar', false)
            ->assertSee('1 selected');
    }

    public function test_full_request_log_page_is_available(): void
    {
        $this->get('/dashboard/requests')
            ->assertOk()
            ->assertSee('Request log')
            ->assertSee('HTTP status')
            ->assertSee('Group repeats')
            ->assertSee('Compact')
            ->assertSee('Comfortable');
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
