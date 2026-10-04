<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WaveTwoServingTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(string $path, array $response = [], array $options = []): MockEndpoint
    {
        $raw = "curl 'https://example.test{$path}'";
        $parsed = app(CurlParser::class)->parse($raw);
        $variant = app(CurlHasher::class)->forOptions($parsed, false, false, true, $options['excluded_query_params'] ?? [], [], $options['path_pattern_enabled'] ?? false);
        $endpoint = MockEndpoint::factory()->create([
            'method' => 'GET', 'raw_curl' => $raw, 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
            'exclude_cookies' => false, 'exclude_auth' => false, 'exclude_headers' => true,
            'signature_version' => $variant->name === 'V6' ? 6 : 2, ...$options,
        ]);
        MockResponse::factory()->for($endpoint, 'endpoint')->create(['body' => '{"result":"unchanged"}', ...$response]);

        return $endpoint;
    }

    public function test_pattern_captures_feed_response_and_callback_context_and_seeded_templates(): void
    {
        $endpoint = $this->endpoint('/users/{id}?noise=old&keep=1', [
            'body_mode' => 'template', 'template' => '{"id":"$request.path.id","method":"$request.method","request":"$request.id","missing":"$request.path.missing"}',
            'seed_mode' => 'request', 'callback_enabled' => true,
        ], ['path_pattern_enabled' => true, 'excluded_query_params' => ['noise']]);
        Queue::fake();
        foreach (['42', 'abc'] as $id) {
            $this->get('/users/'.$id.'?keep=1&noise=other', ['X-Request-ID' => 'wave-two-'.$id])
                ->assertOk()->assertJson(['id' => $id, 'method' => 'GET', 'request' => 'wave-two-'.$id, 'missing' => null]);
        }
        foreach (['42', 'abc'] as $id) {
            Queue::assertPushed(DeliverCallback::class, fn (DeliverCallback $job): bool => ($job->requestContext['path']['id'] ?? null) === $id && $job->requestLogId === 'wave-two-'.$id);
        }
        $this->get('/users/42/orders?keep=1')->assertNotFound();
        $this->get('/users/42?keep=2')->assertNotFound();
        self::assertSame(2, EndpointCallState::query()->sole()->total_match_count);
        self::assertCount(2, EndpointCallState::query()->sole()->recent_call_digests);
    }

    public function test_equal_priority_literal_wins_but_higher_priority_pattern_can_override(): void
    {
        $pattern = $this->endpoint('/users/{id}', ['body' => 'pattern'], ['path_pattern_enabled' => true]);
        $this->endpoint('/users/42', ['body' => 'literal']);
        $this->get('/users/42')->assertOk()->assertContent('literal');
        $pattern->update(['priority' => 5]);
        $this->get('/users/42')->assertOk()->assertContent('pattern');
    }

    public function test_delay_fault_counts_records_and_logs_the_real_matched_call(): void
    {
        $endpoint = $this->endpoint('/slow', ['fault_enabled' => true, 'fault_type' => 'delay', 'fault_delay_ms_min' => 30]);
        Log::shouldReceive('channel')->with('mock_requests')->once()->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(fn (string $level, string $message, array $event): bool => $message === 'mock_request'
            && $event['fault_applied'] === 'delay' && $event['fault_delay_ms'] === 30 && $event['endpoint_id'] === $endpoint->id);
        $start = hrtime(true);
        $this->get('/slow')->assertOk()->assertContent('{"result":"unchanged"}');
        self::assertGreaterThanOrEqual(29, (hrtime(true) - $start) / 1_000_000);
        $state = EndpointCallState::query()->sole();
        self::assertSame(1, $state->total_match_count);
        self::assertSame('/slow', $state->recent_call_digests[0]['path']);
    }

    public function test_body_faults_apply_after_rendering_and_preserve_declared_content_type(): void
    {
        $endpoint = $this->endpoint('/broken', ['body_mode' => 'template', 'template' => '{"value":"hello world","ok":true}', 'fault_enabled' => true, 'fault_type' => 'malformed_body']);
        $result = $this->get('/broken')->assertOk()->assertHeader('Content-Type', 'application/json')->assertContent('{"mockdeck_fault":');
        json_decode($result->getContent());
        self::assertNotSame(JSON_ERROR_NONE, json_last_error());
        $response = $endpoint->responses()->sole();
        $response->update(['fault_type' => 'truncated_body']);
        $result = $this->get('/broken')->assertOk();
        $body = '{"value":"hello world","ok":true}';
        self::assertSame(substr($body, 0, intdiv(strlen($body), 2)), $result->getContent());
        json_decode($result->getContent());
        self::assertNotSame(JSON_ERROR_NONE, json_last_error());
        self::assertSame(2, EndpointCallState::query()->sole()->total_match_count);
    }

    public function test_disabled_and_zero_probability_faults_preserve_normal_response_and_selection(): void
    {
        $endpoint = $this->endpoint('/unchanged', ['body' => "raw bytes\n", 'headers' => ['Content-Type' => 'text/plain', 'X-Test' => 'same']]);
        $normal = $this->get('/unchanged')->assertOk()->assertHeader('X-Test', 'same')->assertContent("raw bytes\n");
        $response = $endpoint->responses()->sole();
        $response->update(['fault_enabled' => true, 'fault_type' => 'truncated_body', 'fault_probability' => 0]);
        $this->get('/unchanged')->assertOk()->assertContent($normal->getContent())->assertHeader('X-Test', 'same');
        self::assertSame(2, EndpointCallState::query()->sole()->total_match_count);
    }
}
