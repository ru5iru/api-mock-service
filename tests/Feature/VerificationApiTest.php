<?php

namespace Tests\Feature;

use App\Http\Middleware\DashboardAccess;
use App\Models\ApiToken;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = 'mdv_'.str_repeat('a', 64);
        ApiToken::query()->create(['name' => 'verification', 'token_hash' => hash('sha256', $this->token)]);
    }

    public function test_token_is_required_even_for_an_authenticated_operator_or_disabled_dashboard_auth(): void
    {
        $endpoint = $this->endpoint();
        $url = $this->url($endpoint, 'calls').'?environment=Development';
        $this->getJson($url)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->getJson('/api/v1/verify/endpoints/unknown/calls?environment=Development')->assertUnauthorized();
        $this->withSession([DashboardAccess::SESSION_KEY => true])->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer mdv_'.str_repeat('b', 64)])->assertUnauthorized();
        $this->getJson($url, $this->headers())->assertOk()->assertJsonPath('total_match_count', 0)->assertJsonCount(0, 'recent_calls');
        self::assertNotNull(ApiToken::query()->sole()->last_used_at);
        self::assertSame(0, EndpointCallState::query()->count());
        $this->get('/api/v1/verify/missing', $this->headers())->assertNotFound()->assertJsonPath('message', 'Unknown verification API route.');
    }

    public function test_cli_token_generation_is_show_once_hash_only_and_rotation_revokes_the_previous_token(): void
    {
        ApiToken::query()->delete();
        self::assertSame(0, Artisan::call('mockdeck:api-token'));
        preg_match('/mdv_[a-f0-9]{64}/', Artisan::output(), $matches);
        $first = $matches[0];
        self::assertSame(hash('sha256', $first), ApiToken::query()->sole()->token_hash);
        self::assertStringNotContainsString($first, json_encode(DB::table('api_tokens')->first()));
        self::assertArrayNotHasKey('token_hash', ApiToken::query()->sole()->toArray());
        self::assertSame(1, Artisan::call('mockdeck:api-token'));
        self::assertDoesNotMatchRegularExpression('/mdv_[a-f0-9]{64}/', Artisan::output());
        self::assertSame(0, Artisan::call('mockdeck:api-token', ['--rotate' => true]));
        preg_match('/mdv_[a-f0-9]{64}/', Artisan::output(), $matches);
        self::assertNotSame($first, $matches[0]);
        self::assertSame(1, ApiToken::query()->count());
        $url = $this->url($this->endpoint(), 'calls').'?environment=Development';
        $this->getJson($url, ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer '.$matches[0]])->assertOk();
    }

    public function test_real_calls_are_counted_and_all_comparators_use_lifetime_counts(): void
    {
        $endpoint = $this->endpoint();
        foreach (range(1, 3) as $call) {
            $this->get('/verify-target', ['X-Call' => (string) $call])->assertOk()->assertContent('original body');
        }
        $this->getJson($this->url($endpoint, 'calls').'?environment=Development', $this->headers())
            ->assertOk()->assertJsonPath('total_match_count', 3)->assertJsonCount(3, 'recent_calls')
            ->assertJsonPath('recent_calls.0.method', 'GET')->assertJsonPath('recent_calls.0.path', '/verify-target')
            ->assertJsonPath('recent_calls.2.header_digest.fields.x-call.value', '3')->assertJsonMissingPath('sequence_position');
        foreach ([['equals', 3, true], ['equals', 2, false], ['at_least', 2, true], ['at_least', 4, false], ['at_most', 4, true], ['at_most', 2, false]] as [$comparator, $expected, $passed]) {
            $this->assertion($endpoint, $comparator, $expected)->assertStatus($passed ? 200 : 422)
                ->assertJsonPath('passed', $passed)->assertJsonPath('actual_count', 3);
        }
    }

    public function test_matching_predicates_share_response_rule_semantics_for_real_headers_query_and_json(): void
    {
        $body = '{"user":{"name":"Ada","enabled":true,"missing":null}}';
        $endpoint = $this->endpoint('/verify-target?code=AB123', $body);
        foreach (['blue', 'red', 'blue'] as $tenant) {
            $this->call('POST', '/verify-target?code=AB123', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TENANT' => $tenant], $body)->assertOk();
        }
        $matching = [
            $this->condition('header', 'X-TENANT', 'equals', 'blue'),
            $this->condition('query', 'code', 'regex', '/^AB[0-9]+$/'),
            $this->condition('body_json_path', 'user.name', 'contains', 'Ad'),
            $this->condition('body_json_path', 'user.enabled', 'equals', 'true'),
            $this->condition('body_json_path', 'user.missing', 'exists'),
        ];
        foreach ([['equals', 2, true], ['equals', 3, false], ['at_least', 1, true], ['at_least', 3, false], ['at_most', 3, true], ['at_most', 1, false]] as [$comparator, $expected, $passed]) {
            $this->assertion($endpoint, $comparator, $expected, $matching)->assertStatus($passed ? 200 : 422)
                ->assertJsonPath('passed', $passed)->assertJsonPath('actual_count', 2);
        }
        $this->assertion($endpoint, 'equals', 0, [$this->condition('body_json_path', 'absent', 'exists')])->assertOk()->assertJsonPath('actual_count', 0);
    }

    public function test_ring_evicts_old_calls_without_silently_claiming_full_history_for_filtered_assertions(): void
    {
        $endpoint = $this->endpoint();
        foreach (range(1, 21) as $call) {
            $this->get('/verify-target', ['X-Call' => (string) $call])->assertOk();
        }
        $this->getJson($this->url($endpoint, 'calls').'?environment=Development', $this->headers())
            ->assertOk()->assertJsonPath('total_match_count', 21)->assertJsonCount(20, 'recent_calls')
            ->assertJsonPath('recent_calls.0.header_digest.fields.x-call.value', '2')->assertJsonPath('window.unretained_calls', 1);
        $this->assertion($endpoint, 'equals', 21)->assertOk()->assertJsonPath('actual_count', 21);
        $condition = [$this->condition('header', 'x-call', 'equals', '1')];
        $this->assertion($endpoint, 'equals', 0, $condition)->assertUnprocessable()->assertJsonPath('verdict', 'indeterminate')
            ->assertJsonPath('actual_count', null)->assertJsonPath('count_bounds.minimum', 0)->assertJsonPath('count_bounds.maximum', 1);
        $this->assertion($endpoint, 'at_most', 1, $condition)->assertOk()->assertJsonPath('passed', true);
        $this->assertion($endpoint, 'at_least', 2, $condition)->assertUnprocessable()->assertJsonPath('verdict', 'failed');
        $this->assertion($endpoint, 'at_least', 20, [$this->condition('header', 'x-call', 'exists')])->assertOk();
    }

    public function test_sensitive_and_oversized_values_support_exact_hash_matching_but_not_unprovable_contains(): void
    {
        $endpoint = $this->endpoint();
        $large = str_repeat('z', 300);
        $this->get('/verify-target', ['Authorization' => 'Bearer private-value', 'X-Large' => $large])->assertOk();
        $recent = EndpointCallState::query()->sole()->recent_call_digests;
        self::assertArrayNotHasKey('value', $recent[0]['header_digest']['fields']['authorization']);
        self::assertArrayNotHasKey('value', $recent[0]['header_digest']['fields']['x-large']);
        self::assertStringNotContainsString('private-value', json_encode($recent));
        $this->assertion($endpoint, 'equals', 1, [$this->condition('header', 'Authorization', 'equals', 'Bearer private-value')])->assertOk();
        $this->assertion($endpoint, 'equals', 1, [$this->condition('header', 'X-Large', 'equals', $large)])->assertOk();
        $this->assertion($endpoint, 'equals', 1, [$this->condition('header', 'Authorization', 'contains', 'private')])
            ->assertUnprocessable()->assertJsonPath('verdict', 'indeterminate');
        // A known false AND term resolves a call even when another term is unknown.
        $this->assertion($endpoint, 'equals', 0, [$this->condition('header', 'Authorization', 'contains', 'private'), $this->condition('header', 'absent', 'exists')])->assertOk();
    }

    public function test_resets_are_environment_scoped_runtime_only_and_do_not_preseed_state(): void
    {
        $endpoint = $this->endpoint();
        $otherEndpoint = $this->endpoint('/verify-other');
        $unused = $this->endpoint('/verify-unused');
        $endpoint->update(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        $endpoint->responses()->first()->update(['sequence_order' => 0]);
        $endpoint->responses()->first()->rules()->create(['field_type' => 'header', 'field_name' => 'X-Call', 'operator' => 'exists', 'priority' => 0]);
        $default = Environment::query()->where('name', 'Development')->firstOrFail();
        $other = Environment::query()->create(['name' => 'CI']);
        foreach ([$default, $other] as $environment) {
            app(EnvironmentContext::class)->activate($environment);
            $this->get('/verify-target')->assertOk();
            $this->get('/verify-other')->assertOk();
        }
        $before = $this->configuration();
        $this->getJson($this->url($endpoint, 'calls').'?environment=Development', $this->headers())->assertOk()->assertJsonPath('sequence_position', 1);
        $this->postJson($this->url($endpoint, 'reset'), ['environment' => 'Development'], $this->headers())->assertOk()->assertJsonPath('state_rows_reset', 1);
        $this->getJson($this->url($endpoint, 'calls').'?environment=Development', $this->headers())->assertOk()
            ->assertJsonPath('total_match_count', 0)->assertJsonPath('sequence_position', 0)->assertJsonPath('last_matched_at', null)->assertJsonCount(0, 'recent_calls');
        $this->getJson($this->url($endpoint, 'calls').'?environment=CI', $this->headers())->assertOk()->assertJsonPath('total_match_count', 1)->assertJsonPath('sequence_position', 1);
        $this->getJson($this->url($otherEndpoint, 'calls').'?environment=Development', $this->headers())->assertOk()->assertJsonPath('total_match_count', 1);
        $this->postJson('/api/v1/verify/reset-all?environment=Development', [], $this->headers())->assertOk()->assertJsonPath('state_rows_reset', 2);
        $this->getJson($this->url($otherEndpoint, 'calls').'?environment=Development', $this->headers())->assertOk()->assertJsonPath('total_match_count', 0);
        self::assertSame(2, EndpointCallState::query()->where('environment_id', $other->id)->sum('total_match_count'));
        $this->postJson($this->url($unused, 'reset'), ['environment' => 'Development'], $this->headers())->assertOk()->assertJsonPath('state_rows_reset', 0);
        self::assertFalse(EndpointCallState::query()->where('endpoint_id', $unused->id)->exists());
        self::assertSame($before, $this->configuration());
    }

    public function test_invalid_environment_predicates_comparators_and_regex_return_json_validation_errors(): void
    {
        $endpoint = $this->endpoint();
        $this->get($this->url($endpoint, 'calls'), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->getJson($this->url($endpoint, 'calls').'?environment=missing', $this->headers())->assertNotFound();
        foreach ([
            ['comparator' => 'greater'],
            ['expect_count' => -1],
            ['matching' => [$this->condition('path', 'id', 'exists')]],
            ['matching' => [$this->condition('header', 'x', 'equals')]],
            ['matching' => [$this->condition('header', 'x', 'regex', '/invalid[')]],
        ] as $override) {
            $this->postJson($this->url($endpoint, 'assert'), array_replace(['environment' => 'Development', 'expect_count' => 0, 'comparator' => 'equals'], $override), $this->headers())->assertUnprocessable();
        }
    }

    private function endpoint(string $path = '/verify-target', ?string $body = null): MockEndpoint
    {
        $curl = 'curl --request '.($body === null ? 'GET' : 'POST')." 'https://upstream.example.test".$path."'";
        if ($body !== null) {
            $curl .= " --header 'Content-Type: application/json' --data '".$body."'";
        }
        $parsed = app(CurlParser::class)->parse($curl);
        $signature = app(CurlHasher::class)->forOptions($parsed, false, false, true);
        $endpoint = MockEndpoint::factory()->create(['raw_curl' => $curl, 'method' => $parsed->method, 'normalized_curl' => $signature->normalized, 'curl_hash' => $signature->hash]);
        $endpoint->responses()->create(['body' => 'original body']);

        return $endpoint;
    }

    private function condition(string $field, string $name, string $operator, ?string $value = null): array
    {
        return ['field_type' => $field, 'field_name' => $name, 'operator' => $operator, 'value' => $value];
    }

    private function assertion(MockEndpoint $endpoint, string $comparator, int $expected, array $conditions = [])
    {
        return $this->postJson($this->url($endpoint, 'assert'), ['environment' => 'Development', 'expect_count' => $expected, 'comparator' => $comparator, 'matching' => $conditions], $this->headers());
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function url(MockEndpoint $endpoint, string $action): string
    {
        return '/api/v1/verify/endpoints/'.$endpoint->uuid.'/'.$action;
    }

    private function configuration(): string
    {
        return json_encode(array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), ['mock_endpoints', 'mock_responses', 'response_rules']), JSON_THROW_ON_ERROR);
    }
}
