<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class RequestContextTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_preview_api_uses_sample_request_and_active_environment(): void
    {
        Environment::query()->sole()->variables()->create(['key' => 'NAME', 'value' => 'Development', 'is_secret' => false]);
        $this->postJson('/api/response-templates/preview', [
            'template' => '{"method":"$request.method","id":"$request.id","missing":"$request.json.unknown","environment":"{{env.NAME}}","greeting":"Hello {{$request.method}}"}',
        ])->assertOk()
            ->assertJsonPath('output.method', 'POST')
            ->assertJsonPath('output.id', 'preview')
            ->assertJsonPath('output.missing', null)
            ->assertJsonPath('output.environment', 'Development')
            ->assertJsonPath('output.greeting', 'Hello POST')
            ->assertJsonPath('synthetic_context', true);
    }

    public function test_response_preview_does_not_expose_secret_environment_values(): void
    {
        Environment::query()->sole()->variables()->create(['key' => 'TOKEN', 'value' => 'secret-preview-value', 'is_secret' => true]);
        $result = $this->postJson('/api/response-templates/preview', ['template' => '{"secret":"{{env.TOKEN}}"}'])
            ->assertUnprocessable()
            ->assertJsonPath('output', null)
            ->assertJsonFragment(['code' => 'SECRET_PREVIEW_HIDDEN']);
        self::assertStringNotContainsString('secret-preview-value', $result->getContent());
        $this->postJson('/api/response-templates/validate', ['template' => '{"missing":"{{env.NOT_SET}}"}'])
            ->assertOk()->assertJsonPath('issues', []);
    }

    public function test_escaped_and_literal_secret_tokens_remain_literal_in_preview(): void
    {
        Environment::query()->sole()->variables()->create(['key' => 'TOKEN', 'value' => 'secret-preview-value', 'is_secret' => true]);
        $this->postJson('/api/response-templates/preview', ['template' => '{"escaped":"\\\\{{env.TOKEN}}","raw":{"$literal":"{{env.TOKEN}}"}}'])
            ->assertOk()
            ->assertJsonPath('output.escaped', '{{env.TOKEN}}')
            ->assertJsonPath('output.raw', '{{env.TOKEN}}')
            ->assertJsonPath('synthetic_context', false);
    }

    public function test_export_import_preserves_context_template_text_and_rendering_in_fresh_configuration(): void
    {
        $environment = Environment::query()->sole();
        $environment->variables()->create(['key' => 'NAME', 'value' => 'Development', 'is_secret' => false]);
        $template = "{\n  \"method\": \"\$request.method\",\n  \"id\": \"\$request.id\",\n  \"value\": \"\$request.json.value\",\n  \"env\": \"{{env.NAME}}\"\n}";
        $response = MockResponse::factory()->create(['body_mode' => 'template', 'template' => $template]);
        $contexts = app(TemplateContext::class);
        $context = $contexts->build($contexts->capture(Request::create('/users', 'POST', [], [], [], [], '{"value":42}'), 'request-42'));
        $before = app(ResponseTemplateEngine::class)->renderResponse($response, null, $context)->json;
        $export = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        MockEndpoint::query()->delete();
        self::assertSame(0, MockResponse::query()->count());
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($export, ImportMode::CreateOnly);
        self::assertTrue($plan->canApply(), json_encode($plan->errors));
        $importer->apply($plan->token, $plan->digest, true);
        $imported = MockResponse::query()->sole();
        self::assertSame($template, $imported->template);
        self::assertSame($before, app(ResponseTemplateEngine::class)->renderResponse($imported, null, $context)->json);
    }
}
