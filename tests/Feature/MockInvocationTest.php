<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\IncomingRequestFactory;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\EndpointMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

final class MockInvocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_saved_through_dashboard_component_handles_normalized_near_miss(): void
    {
        $curl = <<<'CURL'
curl --request POST 'https://api.example.test/v1/users?b=2&a=1' \
  --header 'Content-Type: application/json' \
  --data '{"name":"Ada","role":"admin"}'
CURL;

        Livewire::test(EndpointForm::class)
            ->set('name', 'Create user')
            ->set('rawCurl', $curl)
            ->set('excludeHeaders', true)
            ->call('save')
            ->assertHasNoErrors();

        $endpoint = MockEndpoint::query()->sole();
        self::assertTrue($endpoint->exclude_headers);

        $captured = app(IncomingRequestFactory::class)->fromRequest(Request::create(
            '/v1/users?b=2&a=1',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_MOCK_ORIGINAL_URL' => 'https://api.example.test/v1/users?a=1&b=2',
                'HTTP_X_EXTRA_CLIENT_HEADER' => 'ignored-by-v5',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"role":"admin","name":"Ada"}',
        ));
        self::assertNotNull(app(EndpointMatcher::class)->match($captured));

        $endpoint->responses()->create([
            'status_code' => 201,
            'headers' => ['Content-Type' => 'application/json', 'X-Mock' => 'yes'],
            'body' => '{"created":true}',
            'delay_ms' => 0,
            'weight' => 1,
        ]);

        $response = $this->call(
            'POST',
            '/v1/users?b=2&a=1',
            [],
            [],
            [],
            [
                'HTTP_X_MOCK_ORIGINAL_URL' => 'https://api.example.test/v1/users?a=1&b=2',
                'HTTP_X_EXTRA_CLIENT_HEADER' => 'ignored-by-v5',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"role":"admin","name":"Ada"}',
        );

        $response->assertCreated()
            ->assertHeader('X-Mock', 'yes')
            ->assertContent('{"created":true}');
    }

    public function test_unmatched_request_returns_diagnostic_json_404(): void
    {
        $response = $this->withHeader(
            'X-Mock-Original-Url',
            'https://api.example.test/not-configured?x=1',
        )->get('/not-configured?x=1');

        $response->assertNotFound()
            ->assertJsonPath('error', 'No mock configured for this request')
            ->assertJsonPath('method', 'GET')
            ->assertJsonPath('url', 'https://api.example.test/not-configured?x=1');
    }

    public function test_normalized_string_fallback_matches_when_stored_hash_has_drifted(): void
    {
        $endpoint = $this->storeEndpoint(
            "curl 'https://api.example.test/health'",
            excludeHeaders: true,
        );
        $endpoint->update(['curl_hash' => str_repeat('0', 64)]);
        $endpoint->responses()->create([
            'status_code' => 204,
            'headers' => null,
            'body' => null,
            'delay_ms' => 0,
            'weight' => 1,
        ]);

        $match = app(EndpointMatcher::class)->match(new ParsedCurl(
            'GET',
            'https://api.example.test/health',
            [['name' => 'X-Anything', 'value' => 'one']],
        ));

        self::assertNotNull($match);
        self::assertSame('fallback', $match->tier);
        self::assertSame($endpoint->id, $match->endpoint->id);

        $this->withHeader('X-Mock-Original-Url', 'https://api.example.test/health')
            ->get('/health')
            ->assertNoContent();
    }

    public function test_matched_endpoint_without_responses_returns_configuration_error(): void
    {
        $endpoint = $this->storeEndpoint("curl 'https://api.example.test/empty'", excludeHeaders: true);

        $this->withHeader('X-Mock-Original-Url', 'https://api.example.test/empty')
            ->get('/empty')
            ->assertStatus(500)
            ->assertJsonPath('endpoint_id', $endpoint->id);
    }

    private function storeEndpoint(
        string $curl,
        bool $excludeCookies = false,
        bool $excludeAuth = false,
        bool $excludeHeaders = false,
    ): MockEndpoint {
        $parsed = app(CurlParser::class)->parse($curl);
        $variant = app(CurlHasher::class)->forOptions(
            $parsed,
            $excludeCookies,
            $excludeAuth,
            $excludeHeaders,
        );

        return MockEndpoint::query()->create([
            'name' => 'Feature test endpoint',
            'method' => $parsed->method,
            'raw_curl' => $curl,
            'normalized_curl' => $variant->normalized,
            'curl_hash' => $variant->hash,
            'exclude_cookies' => $excludeCookies,
            'exclude_auth' => $excludeAuth,
            'exclude_headers' => $excludeHeaders,
        ]);
    }
}
