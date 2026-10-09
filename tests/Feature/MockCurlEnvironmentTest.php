<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointIndex;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use App\Services\Curl\MockCurlEnvironmentResolver;
use App\Services\Curl\ParsedCurl;
use App\Services\Environments\EnvironmentContext;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Matching\EndpointMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class MockCurlEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_encoded_postman_query_and_bearer_tokens_use_saved_values_and_still_match(): void
    {
        $env = app(EnvironmentContext::class)->active();
        $env->variables()->create(['key' => 'cid_login_id_stg', 'value' => 'user+one & 42%', 'is_secret' => false]);
        $env->variables()->create(['key' => 'stg_token', 'value' => "saved-token's-value", 'is_secret' => true]);
        $json = json_encode(['info' => ['name' => 'Copy regression', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'], 'item' => [[
            'name' => 'Cart', 'request' => ['method' => 'GET', 'url' => ['path' => ['carts'], 'query' => [['key' => 'loginId', 'value' => '{{cid_login_id_stg}}'], ['key' => 'loginSystem', 'value' => 'CID']]], 'header' => [['key' => 'Authorization', 'value' => 'Bearer {{stg_token}}']]],
            'response' => [['code' => 200, 'body' => '{"ok":true}']],
        ]]], JSON_THROW_ON_ERROR);
        $imports = app(MappedImportService::class);
        $plan = $imports->preview(app(PostmanMapper::class), $json, ImportMode::CreateOnly);
        $imports->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        $before = $endpoint->getAttributes();
        config(['app.url' => 'http://localhost:18473']);
        $component = Livewire::test(EndpointIndex::class)->assertDontSee("saved-token's-value");
        $curl = app(EndpointIndex::class)->copyMockCurl($endpoint->id);
        $component->call('copyMockCurl', $endpoint->id)->assertReturned($curl);
        self::assertStringContainsString('loginId=user%2Bone%20%26%2042%25&loginSystem=CID', $curl);
        self::assertStringNotContainsString('%7B%7B', $curl);
        $parsed = app(CurlParser::class)->parse($curl);
        self::assertSame("Bearer saved-token's-value", $parsed->headers[0]['value']);
        self::assertTrue(app(EndpointMatcher::class)->match($parsed)->endpoint->is($endpoint));
        self::assertSame($before, $endpoint->fresh()->getAttributes());
    }

    public function test_copy_uses_the_current_environment_and_latest_values(): void
    {
        $active = app(EnvironmentContext::class)->active();
        $active->variables()->create(['key' => 'id', 'value' => 'first', 'is_secret' => false]);
        $endpoint = MockEndpoint::factory()->create(['raw_curl' => "curl 'https://source.test/cart?id=%7b%7bid%7d%7d&literal=a+b%2Fc'"]);
        config(['app.url' => 'http://localhost:18473']);
        self::assertStringContainsString('id=first&literal=a+b%2Fc', app(EndpointIndex::class)->copyMockCurl($endpoint->id));
        $other = Environment::query()->create(['name' => 'Other', 'is_default' => false]);
        $variable = $other->variables()->create(['key' => 'id', 'value' => 'second', 'is_secret' => false]);
        app(EnvironmentContext::class)->activate($other);
        self::assertStringContainsString('id=second', app(EndpointIndex::class)->copyMockCurl($endpoint->id));
        $variable->update(['value' => 'latest']);
        self::assertStringContainsString('id=latest', app(EndpointIndex::class)->copyMockCurl($endpoint->id));
    }

    public function test_missing_variable_blocks_copy_with_a_specific_toast(): void
    {
        $endpoint = MockEndpoint::factory()->create(['raw_curl' => "curl 'https://source.test/cart?id=%7B%7Bmissing_id%7D%7D'"]);
        Livewire::test(EndpointIndex::class)->call('copyMockCurl', $endpoint->id)->assertReturned(null)
            ->assertDispatched('toast', fn ($name, $params) => $params['tone'] === 'danger' && str_contains($params['message'], 'missing_id'));
    }

    public function test_basic_credentials_and_empty_values_resolve_but_bodies_remain_literal(): void
    {
        $env = app(EnvironmentContext::class)->active();
        $env->variables()->create(['key' => 'user', 'value' => 'alice', 'is_secret' => false]);
        $env->variables()->create(['key' => 'password', 'value' => '', 'is_secret' => true]);
        $request = new ParsedCurl('POST', 'https://source.test/cart?password={{password}}', [['name' => 'Authorization', 'value' => 'Basic '.base64_encode('{{user}}:{{password}}')]], '{"captured":"{{not_a_copy_variable}}"}');
        $resolved = app(MockCurlEnvironmentResolver::class)->resolve($request);
        self::assertSame('https://source.test/cart?password=', $resolved->url);
        self::assertSame('Basic '.base64_encode('alice:'), $resolved->headers[0]['value']);
        self::assertSame($request->body, $resolved->body);
    }

    public function test_header_values_cannot_inject_newlines_and_substitution_is_not_recursive(): void
    {
        $env = app(EnvironmentContext::class)->active();
        $variable = $env->variables()->create(['key' => 'token', 'value' => '{{another}}', 'is_secret' => true]);
        $request = new ParsedCurl('GET', 'https://source.test/', [['name' => 'Authorization', 'value' => 'Bearer {{token}}']]);
        self::assertSame('Bearer {{another}}', app(MockCurlEnvironmentResolver::class)->resolve($request)->headers[0]['value']);
        $variable->update(['value' => "secret\r\nX-Injected: yes"]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe Environment value for header Authorization');
        app(MockCurlEnvironmentResolver::class)->resolve($request);
    }

    public function test_ordinary_commands_are_byte_identical(): void
    {
        config(['app.url' => 'http://localhost:18473']);
        $request = new ParsedCurl('GET', 'https://source.test/cart?plain=a+b%2Fc', [['name' => 'X-Test', 'value' => 'plain']]);
        $builder = app(MockCurlBuilder::class);
        self::assertSame($builder->build($request), $builder->build(app(MockCurlEnvironmentResolver::class)->resolve($request)));
    }
}
