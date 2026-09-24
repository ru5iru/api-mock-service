<?php

namespace Tests\Unit;

use App\Services\Templates\FakerMethodCatalog;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateRenderException;
use App\Services\Templates\TemplateRenderer;
use Tests\TestCase;

final class ResponseTemplateEngineTest extends TestCase
{
    public function test_typed_and_interpolated_faker_values_render_without_eval(): void
    {
        $result = $this->engine()->preview(<<<'JSON'
{
  "count": "$number.int({\"min\":7,\"max\":7})",
  "enabled": "$datatype.boolean(100)",
  "label": "User {{person.firstName}}"
}
JSON, 'en', 'fixed', 42);

        self::assertSame([], array_filter($result['issues'], fn (array $issue): bool => $issue['severity'] === 'error'));
        self::assertSame(7, $result['output']->count);
        self::assertIsBool($result['output']->enabled);
        self::assertIsString($result['output']->label);
        self::assertStringStartsWith('User ', $result['output']->label);
    }

    public function test_escapes_currency_and_repeat_indexes_are_preserved(): void
    {
        $result = $this->engine()->preview(<<<'JSON'
{
  "dollar": "$$person.firstName",
  "currency": "$100",
  "mustache": "\\{{person.firstName}}",
  "rows": {"$repeat": 2, "$item": {"typed": "$index", "text": "row-{{$index}}"}}
}
JSON, 'en', 'fixed', 7);

        self::assertSame('$person.firstName', $result['output']->dollar);
        self::assertSame('$100', $result['output']->currency);
        self::assertSame('{{person.firstName}}', $result['output']->mustache);
        self::assertSame(0, $result['output']->rows[0]->typed);
        self::assertSame('row-1', $result['output']->rows[1]->text);
    }

    public function test_nested_directives_and_long_form_faker_render(): void
    {
        $result = $this->engine()->preview(<<<'JSON'
{
  "items": {
    "$repeat": {"min": 3, "max": 3},
    "$item": {
      "value": {"$faker": "number.int", "$args": [{"min": 4, "max": 4}]},
      "choice": {"$pick": ["a", "b"], "$weights": [1, 2]},
      "optional": {"$maybe": 1, "$value": true, "$else": false},
      "raw": {"$literal": "$person.firstName"}
    }
  }
}
JSON, 'en', 'fixed', 11);

        self::assertCount(3, $result['output']->items);
        self::assertSame(4, $result['output']->items[0]->value);
        self::assertTrue($result['output']->items[0]->optional);
        self::assertSame('$person.firstName', $result['output']->items[0]->raw);
    }

    public function test_unknown_blocked_and_prototype_shaped_methods_are_errors(): void
    {
        foreach ([
            '$person.fristName' => 'UNKNOWN_METHOD',
            '$helpers.multiple' => 'BLOCKED_METHOD',
            '$__proto__.polluted' => 'BLOCKED_METHOD',
            '$constructor.constructor' => 'BLOCKED_METHOD',
            '$prototype.value' => 'BLOCKED_METHOD',
        ] as $token => $code) {
            $validation = $this->engine()->validate(json_encode(['value' => $token], JSON_THROW_ON_ERROR));
            self::assertTrue($validation->hasErrors(), $token);
            self::assertContains($code, array_column($validation->issueArrays(), 'code'), $token);
        }
    }

    public function test_every_legacy_alias_target_exists_and_reports_a_warning(): void
    {
        $catalog = app(FakerMethodCatalog::class);
        $definitions = $catalog->definitions();

        foreach ($catalog->exactAliases() as $legacy => $target) {
            self::assertArrayHasKey($target, $definitions, $legacy);
            $validation = $this->engine()->validate(json_encode(['value' => '$'.$legacy], JSON_THROW_ON_ERROR));
            self::assertFalse($validation->hasErrors(), $legacy);
            self::assertContains('RENAMED_METHOD', array_column($validation->issueArrays(), 'code'), $legacy);
        }

        foreach ($catalog->prefixAliases() as $legacyPrefix => $targetPrefix) {
            foreach (array_keys($definitions) as $target) {
                if (! str_starts_with($target, $targetPrefix)) {
                    continue;
                }
                $legacy = $legacyPrefix.substr($target, strlen($targetPrefix));
                $validation = $this->engine()->validate(json_encode(['value' => '$'.$legacy], JSON_THROW_ON_ERROR));
                self::assertFalse($validation->hasErrors(), $legacy);
                self::assertContains('RENAMED_METHOD', array_column($validation->issueArrays(), 'code'), $legacy);
            }
        }
    }

    public function test_fixed_and_request_seeds_are_deterministic(): void
    {
        $template = '{"value":"$number.int({\"min\":1,\"max\":1000000})"}';
        $fixedA = $this->engine()->preview($template, 'en', 'fixed', 8128);
        $fixedB = $this->engine()->preview($template, 'en', 'fixed', 8128);
        self::assertSame($fixedA['output']->value, $fixedB['output']->value);

        $validation = $this->engine()->validate($template);
        $renderer = app(TemplateRenderer::class);
        $requestA = $renderer->render($validation->compiled, 'en', 'request', null, str_repeat('a', 64));
        $requestB = $renderer->render($validation->compiled, 'en', 'request', null, str_repeat('a', 64));
        self::assertSame($requestA->json, $requestB->json);
    }

    public function test_locale_switch_and_date_formats_render(): void
    {
        $english = $this->engine()->preview('{"name":"$person.firstName"}', 'en_US', 'fixed', 19);
        $french = $this->engine()->preview('{"name":"$person.firstName"}', 'fr_FR', 'fixed', 19);
        self::assertIsString($english['output']->name);
        self::assertIsString($french['output']->name);

        $date = $this->engine()->preview('{"date":{"$faker":"date.between","$args":[{"from":"2025-01-01","to":"2025-01-01"}],"$format":"date"}}', 'en', 'fixed', 1);
        self::assertSame('2025-01-01', $date['output']->date);

        $invalidFormat = $this->engine()->validate('{"value":{"$faker":"number.int","$format":"date"}}');
        self::assertTrue($invalidFormat->hasErrors());
        self::assertContains('BAD_ARGS', array_column($invalidFormat->issueArrays(), 'code'));
    }

    public function test_all_configured_limits_are_enforced(): void
    {
        config()->set('mock.templates.max_template_bytes', 10);
        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate('{"too":"long"}')->issueArrays(), 'code'));

        config()->set('mock.templates.max_template_bytes', 262144);
        config()->set('mock.templates.max_depth', 2);
        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate('{"a":{"b":{"c":1}}}')->issueArrays(), 'code'));

        config()->set('mock.templates.max_depth', 12);
        config()->set('mock.templates.max_nodes', 2);
        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate('{"a":1,"b":2}')->issueArrays(), 'code'));

        config()->set('mock.templates.max_nodes', 10000);
        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate('{"$repeat":1001,"$item":true}')->issueArrays(), 'code'));

        $largeArgs = str_repeat('a', 4100);
        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate(json_encode(['value' => '$helpers.fromRegExp("'.$largeArgs.'")'], JSON_THROW_ON_ERROR))->issueArrays(), 'code'));

        self::assertContains('LIMIT_EXCEEDED', array_column($this->engine()->validate('{"value":"$helpers.fromRegExp(\"[A-Z]{101}\")"}')->issueArrays(), 'code'));

        config()->set('mock.templates.max_output_bytes', 10);
        $this->expectException(TemplateRenderException::class);
        $this->engine()->preview('{"value":"a value longer than ten bytes"}', 'en', 'fixed', 1);
    }

    public function test_catalog_samples_match_their_declared_return_types(): void
    {
        $catalog = app(FakerMethodCatalog::class)->catalog();
        self::assertNotEmpty($catalog);
        self::assertSame($catalog, array_values($catalog));
        self::assertContains('internet.username', array_column($catalog, 'id'));
        self::assertContains('number.int', array_column($catalog, 'id'));
    }

    public function test_rendered_node_limit_is_enforced(): void
    {
        config()->set('mock.templates.max_rendered_nodes', 3);

        $this->expectException(TemplateRenderException::class);
        $this->engine()->preview('{"$repeat":4,"$item":{"value":true}}', 'en', 'fixed', 1);
    }

    private function engine(): ResponseTemplateEngine
    {
        return app(ResponseTemplateEngine::class);
    }
}
