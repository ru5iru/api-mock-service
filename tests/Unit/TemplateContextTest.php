<?php

namespace Tests\Unit;

use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TemplateContextTest extends TestCase
{
    public function test_request_capture_and_typed_tokens_share_callback_context(): void
    {
        $request = Request::create('https://mock.test/users', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"user":{"id":42}}');
        $capture = app(TemplateContext::class)->capture($request, 'request-42');
        $template = '{"method":"$request.method","id":"$request.id","body":"$request.body","user":"$request.json.user.id","absent":"$request.json.not.present","label":"Hello {{$request.method}}","env":"{{env.NAME}}"}';
        $compiled = app(ResponseTemplateEngine::class)->validate($template);
        self::assertFalse($compiled->hasErrors());
        $result = app(TemplateRenderer::class)->render($compiled->compiled, 'en', 'fixed', 1, null, ['request' => $capture, 'env' => ['NAME' => 'Development']]);

        self::assertSame('POST', $result->output->method);
        self::assertSame('request-42', $result->output->id);
        self::assertSame('{"user":{"id":42}}', $result->output->body);
        self::assertSame(42, $result->output->user);
        self::assertNull($result->output->absent);
        self::assertSame('Hello POST', $result->output->label);
        self::assertSame('Development', $result->output->env);
    }

    public function test_large_bodies_are_absent_and_capture_exposes_warning_metadata(): void
    {
        $contexts = app(TemplateContext::class);
        $request = Request::create('/users', 'POST', [], [], [], [], str_repeat('x', 65537));
        $capture = $contexts->capture($request, 'large-body');
        self::assertTrue($capture['body_context_omitted']);
        self::assertSame(65537, $capture['body_bytes']);
        self::assertSame(65536, $capture['context_limit_bytes']);
        self::assertNull($capture['body']);
        self::assertNull($capture['json']);
        $validation = app(ResponseTemplateEngine::class)->validate('{"body":"$request.body","path":"$request.json.id","method":"$request.method"}');
        $result = app(TemplateRenderer::class)->render($validation->compiled, 'en', 'fixed', 1, null, ['request' => $capture]);
        self::assertSame('{"body":null,"path":null,"method":"POST"}', $result->json);
        $boundary = $contexts->capture(Request::create('/users', 'POST', [], [], [], [], str_repeat('x', 65536)), 'boundary');
        self::assertFalse($boundary['body_context_omitted']);
        self::assertSame(65536, strlen($boundary['body']));
    }

    public function test_unknown_fields_and_misspelled_top_level_tokens_fail_validation(): void
    {
        foreach (['$request.methd', '$requst.method', 'Hello {{$request.methd}}', '{{$requst.method}}', '$request.method.bad'] as $token) {
            $validation = app(ResponseTemplateEngine::class)->validate(json_encode(['value' => $token], JSON_THROW_ON_ERROR));
            self::assertTrue($validation->hasErrors(), $token);
            self::assertContains('UNKNOWN_METHOD', array_column($validation->issueArrays(), 'code'));
        }
        self::assertFalse(app(ResponseTemplateEngine::class)->validate('{"missing":"$request.json.unknown.path"}')->hasErrors());
    }

    public function test_preview_samples_and_json_only_builder_scope(): void
    {
        $engine = app(ResponseTemplateEngine::class);
        $preview = $engine->preview('{"method":"$request.method","id":"$request.id","missing":"$request.json.id"}', 'en', 'fixed', 1);
        self::assertTrue($preview['synthetic_context']);
        self::assertSame('POST', $preview['output']->method);
        self::assertSame('preview', $preview['output']->id);
        self::assertNull($preview['output']->missing);
        foreach (['{"id":"$request.id"}', '{"name":"{{env.NAME}}"}', '{"name":"Hello {{$request.method}}"}'] as $template) {
            self::assertNull(app(TemplateSchemaConverter::class)->templateToSchema($template));
        }
        self::assertNotNull(app(TemplateSchemaConverter::class)->templateToSchema('{"literal":"$$request.id"}'));
    }

    public function test_captured_values_are_data_and_never_reinterpreted_as_template_syntax(): void
    {
        $engine = app(ResponseTemplateEngine::class);
        $validation = $engine->validate('{"inline":"Body $request.body","mustache":"{{$request.body}}","escaped":"\\\\{{$request.methd}}"}');
        self::assertFalse($validation->hasErrors());
        $result = app(TemplateRenderer::class)->render($validation->compiled, 'en', 'fixed', 1, null, [
            'request' => ['body' => '{{env.SECRET}} $request.method', 'method' => 'POST'],
            'env' => ['SECRET' => 'do-not-substitute'],
        ]);
        self::assertSame('Body {{env.SECRET}} $request.method', $result->output->inline);
        self::assertSame('{{env.SECRET}} $request.method', $result->output->mustache);
        self::assertSame('{{$request.methd}}', $result->output->escaped);
    }

    public function test_context_free_template_bytes_do_not_change_with_context(): void
    {
        $engine = app(ResponseTemplateEngine::class);
        $template = '{"literal":"$100","id":"$number.int({\\"min\\":7,\\"max\\":7})","label":"Hello {{person.firstName}}","escaped":"\\\\{{request.method}}"}';
        $validation = $engine->validate($template);
        self::assertFalse($validation->hasErrors());
        $renderer = app(TemplateRenderer::class);
        $before = $renderer->render($validation->compiled, 'en', 'fixed', 19)->json;
        $after = $renderer->render($validation->compiled, 'en', 'fixed', 19, null, ['request' => ['method' => 'POST'], 'env' => []])->json;
        self::assertSame($before, $after);
    }
}
