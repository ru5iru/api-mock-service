<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Livewire\Admin\EndpointForm;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\EndpointMatcher;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class FieldMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_exclusions_are_saved_visible_and_match_changed_values(): void
    {
        Livewire::test(EndpointForm::class)
            ->set('rawCurl', "curl 'https://example.test/items?nonce=123&kind=book' -H 'X-Trace: one' -H 'X-Version: 2'")
            ->set('excludeAuth', false)
            ->set('excludedQueryParams', ['nonce'])
            ->set('excludedHeaderNames', ['x-trace'])
            ->assertSeeHtml('<del>nonce</del>')
            ->assertSeeHtml('<del>X-Trace</del>')
            ->assertSee('1 of 2 excluded')
            ->call('save')->assertHasNoErrors();
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame(['nonce'], $endpoint->excluded_query_params);
        self::assertSame(['x-trace'], $endpoint->excluded_headers);
        self::assertSame('V6', $endpoint->signatureVariant());
        self::assertSame(6, $endpoint->signature_version);
        self::assertSame("GET\n/items?kind=book\nx-version:2\n\n", $endpoint->normalized_curl);
        self::assertTrue(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://another.test/items?nonce=changed&kind=book', [
            ['name' => 'X-Trace', 'value' => 'changed'], ['name' => 'X-Version', 'value' => '2'],
        ]))->endpoint->is($endpoint));
        Livewire::test(EndpointForm::class)->assertSet('showExclusionHint', false);
    }

    public function test_coarse_policies_disable_individual_header_switches_and_retain_explicit_exclusions(): void
    {
        $component = Livewire::test(EndpointForm::class)
            ->set('rawCurl', "curl 'https://example.test/items' -H 'X-Trace: one' -H 'Authorization: sample'")
            ->set('excludedHeaderNames', ['x-trace'])->set('excludeHeaders', true)
            ->assertSee('individual exclusion retained (redundant)')
            ->assertSeeHtml('aria-label="Exclude header X-Trace" disabled')
            ->assertSet('excludedHeaderNames', ['x-trace']);
        $component->set('excludeHeaders', false)->set('excludeAuth', false)
            ->assertSee('ignored: individual exclusion')->call('save')->assertHasNoErrors();
        self::assertSame(['x-trace'], MockEndpoint::query()->sole()->excluded_headers);
    }

    public function test_pattern_syntax_is_literal_until_opted_in_and_hint_can_be_dismissed_without_configuration(): void
    {
        $literal = $this->endpoint('/users/{id}', false);
        self::assertNull(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42', [])));
        self::assertTrue(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/{id}', []))->endpoint->is($literal));
        Livewire::test(EndpointForm::class)->assertSet('showExclusionHint', true)->call('dismissExclusionHint');
        Livewire::test(EndpointForm::class)->assertSet('showExclusionHint', false);
        self::assertSame('V1', $literal->signatureVariant());
    }

    public function test_path_chips_preserve_query_headers_and_body_and_validate_parameter_names(): void
    {
        $curl = "curl --request POST 'https://example.test/users/42?keep=one' -H 'Content-Type: application/json' --data '{\"name\":\"Ada\"}'";
        $component = Livewire::test(EndpointForm::class)->set('rawCurl', $curl)
            ->set('pathPatternEnabled', true)->call('togglePathSegment', 2)
            ->set('pathParameterNames.2', 'id');
        $parsed = app(CurlParser::class)->parse($component->get('rawCurl'));
        self::assertSame('https://example.test/users/{id}?keep=one', $parsed->url);
        self::assertSame('{"name":"Ada"}', $parsed->body);
        self::assertSame([['name' => 'Content-Type', 'value' => 'application/json']], $parsed->headers);
        $component->call('togglePathSegment', 2);
        self::assertSame('https://example.test/users/42?keep=one', app(CurlParser::class)->parse($component->get('rawCurl'))->url);
        $component->call('togglePathSegment', 2)->set('pathParameterNames.2', 'invalid-name')->call('save')->assertHasErrors('pathPatternEnabled');
        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_pattern_matching_is_hybrid_exact_and_literals_win_equal_priority(): void
    {
        $pattern = $this->endpoint('/users/{id}?type=book', true);
        foreach (['42', 'abc'] as $value) {
            $match = app(EndpointMatcher::class)->match(new ParsedCurl('GET', "https://actual.test/users/{$value}?type=book", []));
            self::assertTrue($match->endpoint->is($pattern));
            self::assertSame(['id' => $value], $match->pathParameters);
        }
        foreach (['/users/42/orders?type=book', '/users/?type=book', '/users/42?type=other'] as $target) {
            self::assertNull(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test'.$target, [])));
        }
        self::assertNull(app(EndpointMatcher::class)->match(new ParsedCurl('POST', 'https://actual.test/users/42?type=book', [])));
        self::assertNull(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42?type=book', [['name' => 'X-Extra', 'value' => 'no']])));
        self::assertNull(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42?type=book', [], 'different body')));
        $literal = $this->endpoint('/users/42?type=book', false);
        self::assertTrue(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42?type=book', []))->endpoint->is($literal));
        $pattern->update(['priority' => 10]);
        self::assertTrue(app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42?type=book', []))->endpoint->is($pattern));
    }

    public function test_path_context_is_shared_by_both_renderers_preview_and_test_callback(): void
    {
        Queue::fake();
        $endpoint = $this->endpoint('/users/{id}', true);
        $response = $endpoint->responses()->create([
            'status_code' => 200, 'weight' => 1, 'delay_ms' => 0, 'headers' => [], 'body' => '',
            'body_mode' => 'template', 'template' => '{"id":"$request.path.id","missing":"$request.path.missing"}',
            'locale' => 'en', 'seed_mode' => 'random', 'editor_view' => 'json',
            'callback_enabled' => true, 'callback_url' => 'https://callback.example.test', 'callback_method' => 'POST',
        ]);
        $request = Request::create('/users/42');
        $match = app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42', []));
        $request->attributes->set('mockdeck.path_parameters', $match->pathParameters);
        $contexts = app(TemplateContext::class);
        $context = $contexts->build($contexts->capture($request, 'real-call'));
        $engine = app(ResponseTemplateEngine::class);
        self::assertSame('{"id":"42","missing":null}', $engine->renderResponse($response, null, $context)->json);
        self::assertSame('{"id":"42"}', $engine->renderCallback('{"id":"$request.path.id"}', $response, $context)->json);
        $this->postJson('/api/response-templates/preview', ['template' => $response->template, 'endpoint_id' => $endpoint->id])
            ->assertOk()->assertJsonPath('output.id', 'sample_id')->assertJsonPath('synthetic_context', true);
        self::assertContains('request.path.id', array_column($contexts->catalog($endpoint), 'id'));
        $this->postJson('/api/responses/'.$response->id.'/callback/test')->assertAccepted();
        Queue::assertPushed(DeliverCallback::class, fn ($job) => ($job->requestContext['path']['id'] ?? null) === 'sample_id');
    }

    public function test_legacy_save_keeps_hash_canonical_and_signature_version_unchanged(): void
    {
        $endpoint = $this->endpoint('/legacy?z=last&a=first', false);
        $before = $endpoint->only('normalized_curl', 'curl_hash', 'signature_version');
        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->call('save')->assertHasNoErrors();
        self::assertSame($before, $endpoint->refresh()->only(array_keys($before)));
        self::assertSame('V1', $endpoint->signatureVariant());
        self::assertSame(app(TemplateContext::class)->synthetic(), app(TemplateContext::class)->synthetic(endpoint: $endpoint));
    }

    public function test_migration_does_not_rebuild_any_legacy_canonical_hash_or_version(): void
    {
        $migration = require database_path('migrations/2026_10_03_000001_add_field_matching.php');
        $migration->down();
        $parsed = new ParsedCurl('POST', 'https://api.test/resource?a=one&a=two&z=3', [
            ['name' => 'Cookie', 'value' => 'session=one'], ['name' => 'Authorization', 'value' => 'Bearer sample'],
            ['name' => 'Content-Type', 'value' => 'application/json'], ['name' => 'X-Version', 'value' => '1'],
        ], '{"z":1,"a":2}');
        foreach (app(CurlHasher::class)->variants($parsed) as $name => $variant) {
            MockEndpoint::factory()->create(['name' => $name, 'method' => 'POST', 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'signature_version' => 2]);
        }
        $before = DB::table('mock_endpoints')->orderBy('id')->get(['id', 'normalized_curl', 'curl_hash', 'signature_version'])->toJson();
        $migration->up();
        self::assertSame($before, DB::table('mock_endpoints')->orderBy('id')->get(['id', 'normalized_curl', 'curl_hash', 'signature_version'])->toJson());
        self::assertSame(0, MockEndpoint::query()->where('path_pattern_enabled', true)->count());
        self::assertSame(5, MockEndpoint::query()->whereNull('excluded_query_params')->whereNull('excluded_headers')->count());
    }

    private function endpoint(string $target, bool $pattern): MockEndpoint
    {
        $request = new ParsedCurl('GET', 'https://example.test'.$target, []);
        $variant = app(CurlHasher::class)->forOptions($request, false, false, false, [], [], $pattern);

        return MockEndpoint::factory()->create([
            'raw_curl' => "curl '".$request->url."'", 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
            'exclude_cookies' => false, 'exclude_auth' => false, 'exclude_headers' => false,
            'path_pattern_enabled' => $pattern, 'signature_version' => $pattern ? 6 : 2,
        ]);
    }
}
