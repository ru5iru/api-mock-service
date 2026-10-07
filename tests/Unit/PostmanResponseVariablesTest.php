<?php

namespace Tests\Unit;

use App\Services\Imports\PostmanResponseVariables;
use App\Services\Templates\TemplateCompiler;
use App\Services\Templates\TemplateRenderer;
use Tests\TestCase;

final class PostmanResponseVariablesTest extends TestCase
{
    public function test_every_supported_dynamic_variable_compiles_and_renders(): void
    {
        $rewriter = app(PostmanResponseVariables::class);
        foreach ($rewriter->mappings() as $token => $expression) {
            $warnings = [];
            $result = $rewriter->rewrite(json_encode(['value' => '{{'.$token.'}}']), $warnings);
            self::assertSame('template', $result['body_mode'], $token);
            self::assertStringContainsString('{{'.$expression.'}}', $result['template']);
            $compiled = app(TemplateCompiler::class)->compile($result['template']);
            self::assertFalse($compiled->hasErrors(), $token);
            $output = app(TemplateRenderer::class)->render($compiled->compiled, 'en', 'fixed', 7)->output;
            self::assertNotSame('', $output->value, $token);
            self::assertStringNotContainsString('{{', $output->value);
        }
    }

    public function test_ordinary_example_method_like_text_is_preserved_when_other_fields_convert(): void
    {
        $warnings = [];
        $result = app(PostmanResponseVariables::class)->rewrite('{"literal":"$internet.email","actual":"{{$randomEmail}}"}', $warnings);
        $compiled = app(TemplateCompiler::class)->compile($result['template']);
        $output = app(TemplateRenderer::class)->render($compiled->compiled, 'en', 'fixed', 7)->output;
        self::assertSame('$internet.email', $output->literal);
        self::assertNotFalse(filter_var($output->actual, FILTER_VALIDATE_EMAIL));
    }
}
