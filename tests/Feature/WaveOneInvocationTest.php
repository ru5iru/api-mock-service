<?php

namespace Tests\Feature;

use App\Livewire\Admin\LogViewer;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Templates\ResponseTemplateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class WaveOneInvocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_matched_request_resolves_context_before_sending_the_primary_response(): void
    {
        $body = '{"user":{"name":"Ada"}}';
        $endpoint = $this->endpoint('/wave1-context', $body);
        Environment::query()->sole()->variables()->create(['key' => 'NAME', 'value' => 'Development', 'is_secret' => false]);
        $endpoint->responses()->create([
            'body_mode' => 'template', 'editor_view' => 'json',
            'template' => '{"method":"$request.method","id":"$request.id","body":"$request.body","name":"$request.json.user.name","missing":"$request.json.absent","env":"{{env.NAME}}","inline":"Hello {{$request.method}}"}',
        ]);
        $this->call('POST', '/wave1-context', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'actual-request-42'], $body)
            ->assertOk()->assertHeader('X-Request-ID', 'actual-request-42')
            ->assertJsonPath('method', 'POST')->assertJsonPath('id', 'actual-request-42')
            ->assertJsonPath('body', $body)->assertJsonPath('name', 'Ada')
            ->assertJsonPath('missing', null)->assertJsonPath('env', 'Development')
            ->assertJsonPath('inline', 'Hello POST');
        self::assertSame(1, EndpointCallState::query()->sole()->total_match_count);
    }

    public function test_oversized_body_omits_only_body_context_and_emits_one_structured_warning(): void
    {
        $body = json_encode(['value' => str_repeat('a', 65536)], JSON_THROW_ON_ERROR);
        $endpoint = $this->endpoint('/wave1-large', $body);
        $endpoint->responses()->create([
            'body_mode' => 'template', 'editor_view' => 'json',
            'template' => '{"method":"$request.method","body":"$request.body","value":"$request.json.value","missing":"$request.json.missing","id":"$request.id"}',
        ]);
        Log::shouldReceive('channel')->with('mock_requests')->once()->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(fn (string $level, string $message, array $event): bool => $level === 'warning' && $message === 'mock_request'
            && $event['context_warning'] === 'request_body_context_omitted'
            && $event['body_bytes'] === strlen($body) && $event['context_limit_bytes'] === 65536
            && $event['status_code'] === 200 && $event['selection_mode'] === 'weighted'
            && ! array_key_exists('body', $event));
        $this->call('POST', '/wave1-large', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'large-request'], $body)
            ->assertOk()->assertJsonPath('body', null)->assertJsonPath('value', null)
            ->assertJsonPath('missing', null)->assertJsonPath('method', 'POST')->assertJsonPath('id', 'large-request');
    }

    public function test_sequence_then_context_render_and_exhaustion_share_the_existing_diagnostic_boundary(): void
    {
        $endpoint = $this->endpoint('/wave1-sequence');
        $endpoint->update(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'not_found']);
        foreach ([201, 202] as $order => $status) {
            $endpoint->responses()->create(['status_code' => $status, 'sequence_order' => $order, 'body_mode' => 'template', 'template' => '{"id":"$request.id","order":'.$order.'}']);
        }
        $this->get('/wave1-sequence', ['X-Request-ID' => 'first'])->assertCreated()->assertJsonPath('order', 0)->assertJsonPath('id', 'first');
        $this->get('/wave1-sequence', ['X-Request-ID' => 'second'])->assertStatus(202)->assertJsonPath('order', 1)->assertJsonPath('id', 'second');
        Log::shouldReceive('channel')->with('mock_requests')->once()->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(fn (string $level, string $message, array $event): bool => $message === 'mock_request' && $event['selection_reason'] === 'sequence_exhausted'
            && $event['endpoint_id'] === $endpoint->id && $event['response_id'] === null && $event['status_code'] === 404);
        $this->get('/wave1-sequence')->assertNotFound()->assertJsonPath('reason', 'sequence_exhausted')
            ->assertJsonPath('error', 'No mock configured for this request');
        self::assertSame(3, EndpointCallState::query()->sole()->total_match_count);
        self::assertSame(3, EndpointCallState::query()->sole()->sequence_position);
    }

    public function test_rule_selection_precedes_request_context_render_and_nonmatch_uses_fallback(): void
    {
        $endpoint = $this->endpoint('/wave1-rule');
        $endpoint->update(['selection_mode' => 'rule']);
        $endpoint->responses()->create(['body' => 'fallback', 'is_default' => true]);
        $response = $endpoint->responses()->create(['body_mode' => 'template', 'template' => '{"id":"$request.id","method":"$request.method"}']);
        $response->rules()->create(['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'special', 'priority' => 0]);
        $this->get('/wave1-rule', ['X-Mode' => 'special', 'X-Request-ID' => 'rule-request'])
            ->assertOk()->assertJsonPath('id', 'rule-request')->assertJsonPath('method', 'GET');
        $this->get('/wave1-rule', ['X-Mode' => 'other'])->assertOk()->assertContent('fallback');
        self::assertSame(2, EndpointCallState::query()->sole()->total_match_count);
    }

    public function test_context_free_template_http_bytes_equal_the_original_render_path(): void
    {
        $endpoint = $this->endpoint('/wave1-unchanged');
        $response = $endpoint->responses()->create(['body_mode' => 'template', 'template' => '{"name":"$name.firstName","value":42}', 'seed_mode' => 'fixed', 'seed' => 17, 'locale' => 'en']);
        $expected = app(ResponseTemplateEngine::class)->renderResponse($response)->json;
        $this->get('/wave1-unchanged')->assertOk()->assertContent($expected);
        $this->get('/wave1-unchanged')->assertOk()->assertContent($expected);
    }

    public function test_context_template_export_import_into_a_fresh_instance_preserves_text_and_live_rendering(): void
    {
        $body = '{"user":{"name":"Ada"}}';
        $endpoint = $this->endpoint('/wave1-fresh-import', $body);
        Environment::query()->sole()->variables()->create(['key' => 'NAME', 'value' => 'Imported environment', 'is_secret' => false]);
        $template = "{\n  \"method\": \"\$request.method\",\n  \"id\": \"\$request.id\",\n  \"name\": \"\$request.json.user.name\",\n  \"env\": \"{{env.NAME}}\"\n}";
        $endpoint->responses()->create(['body_mode' => 'template', 'template' => $template, 'editor_view' => 'json', 'locale' => 'en']);
        $file = tempnam(sys_get_temp_dir(), 'mockdeck-fresh-import-');
        try {
            file_put_contents($file, json_encode(['export' => app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), 'body' => $body], JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, base_path('tests/Support/fresh-context-import.php'), $file], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false']);
            $process->setTimeout(60);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $result['initial_variables']);
            self::assertSame(0, $result['states_before_invocation']);
            self::assertSame($template, $result['template']);
            self::assertSame(200, $result['status']);
            self::assertSame(['method' => 'POST', 'id' => 'imported-fresh', 'name' => 'Ada', 'env' => 'Imported environment'], $result['body']);
        } finally {
            @unlink($file);
        }
    }

    public function test_context_warning_and_exhaustion_are_visible_in_logs_and_not_grouped_away(): void
    {
        $endpoint = $this->endpoint('/wave1-log');
        $base = ['method' => 'GET', 'url' => url('/wave1-log'), 'endpoint_id' => $endpoint->id, 'match_tier' => 'hash', 'status_code' => 200, 'selection_mode' => 'weighted', 'selection_reason' => 'selected'];
        $warning = $base + ['request_id' => 'warning', 'context_warning' => 'request_body_context_omitted', 'body_bytes' => 70000, 'context_limit_bytes' => 65536];
        $normal = $base + ['request_id' => 'normal'];
        $exhausted = array_replace($base, ['request_id' => 'exhausted', 'selection_mode' => 'sequence', 'selection_reason' => 'sequence_exhausted', 'status_code' => 404]);
        $file = storage_path('logs/mock-requests-wave1-test.log');
        try {
            file_put_contents($file, implode("\n", array_map(fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR), [$normal, $warning, $exhausted]))."\n");
            Livewire::test(LogViewer::class, ['full' => true])->set('timeRange', 'all')->set('search', '/wave1-log')
                ->assertSee('Context omitted')->assertSee('Sequence exhausted')->assertDontSee('×2')
                ->call('toggleExpanded', hash('sha256', json_encode($warning, JSON_THROW_ON_ERROR)))
                ->assertSee('70,000')->assertSee('65,536')->assertSee('Body and JSON values omitted');
        } finally {
            @unlink($file);
        }
    }

    private function endpoint(string $path, ?string $body = null): MockEndpoint
    {
        $curl = 'curl --request '.($body === null ? 'GET' : 'POST')." '".url($path)."'";
        if ($body !== null) {
            $curl .= " --header 'Content-Type: application/json' --data '".$body."'";
        }
        $parsed = app(CurlParser::class)->parse($curl);
        $variant = app(CurlHasher::class)->forOptions($parsed, false, false, true);

        return MockEndpoint::query()->create(['name' => 'Wave1 integration', 'method' => $parsed->method, 'raw_curl' => $curl, 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'signature_version' => 2, 'exclude_headers' => true]);
    }
}
