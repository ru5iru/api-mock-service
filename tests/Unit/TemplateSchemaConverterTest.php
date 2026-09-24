<?php

namespace Tests\Unit;

use App\Services\Templates\TemplateSchemaConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TemplateSchemaConverterTest extends TestCase
{
    #[DataProvider('representableSchemas')]
    public function test_builder_template_builder_round_trip_is_semantically_identical(array $schema): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $template = $converter->schemaToTemplate($schema);
        $projected = $converter->templateToSchema($template);

        self::assertNotNull($projected);
        self::assertSame($template, $converter->schemaToTemplate($projected));
    }

    public static function representableSchemas(): iterable
    {
        $converter = new TemplateSchemaConverter;
        $types = ['string', 'faker', 'number', 'boolean', 'date'];

        foreach ($types as $index => $type) {
            $row = $converter->emptyRow('field'.$index);
            $row['type'] = $type;
            if ($type === 'faker') {
                $row['method'] = 'number.int';
                $row['args'] = '[{"min":1,"max":9}]';
            } elseif ($type === 'number') {
                $row['mode'] = 'float';
                $row['min'] = 1;
                $row['max'] = 9;
                $row['decimals'] = 2;
            } elseif ($type === 'boolean') {
                $row['mode'] = 'random';
            } elseif ($type === 'date') {
                $row['mode'] = 'between';
                $row['from'] = '2025-01-01';
                $row['to'] = '2025-12-31';
                $row['format'] = 'date';
            } else {
                $row['value'] = 'hello';
            }

            yield $type => [[
                'root' => 'object',
                'count_mode' => 'fixed',
                'count' => 3,
                'min' => 2,
                'max' => 5,
                'fields' => [$row],
            ]];
        }

        $child = $converter->emptyRow('child');
        $child['type'] = 'number';
        $child['mode'] = 'int';
        $object = $converter->emptyRow('nested');
        $object['type'] = 'object';
        $object['children'] = [$child];
        $array = $converter->emptyRow('items');
        $array['type'] = 'array';
        $array['length_mode'] = 'range';
        $array['length_min'] = 2;
        $array['length_max'] = 4;
        $array['item'] = $object;
        $array['nullable'] = 25;

        yield 'nested list and array' => [[
            'root' => 'list',
            'count_mode' => 'range',
            'count' => 3,
            'min' => 2,
            'max' => 5,
            'fields' => [$array],
        ]];
    }

    public function test_nonrepresentable_directives_and_literal_arrays_disable_projection(): void
    {
        $converter = app(TemplateSchemaConverter::class);

        self::assertNull($converter->templateToSchema('{"value":{"$pick":[1,2]}}'));
        self::assertNull($converter->templateToSchema('{"value":{"$literal":"$person.firstName"}}'));
        self::assertNull($converter->templateToSchema('{"value":"Hello {{person.firstName}}"}'));
        self::assertNull($converter->templateToSchema('{"value":[1,2,3]}'));
    }

    public function test_catalog_argument_fields_compile_and_raw_json_arguments_override_them(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $row = $converter->emptyRow('amount');
        $row['type'] = 'faker';
        $row['method'] = 'number.int';
        $row['args_options'] = ['min' => '2', 'max' => '8'];
        $schema = $converter->emptySchema();
        $schema['fields'] = [$row];

        self::assertStringContainsString('"min": 2', $converter->schemaToTemplate($schema));
        self::assertStringContainsString('"max": 8', $converter->schemaToTemplate($schema));

        $row['args'] = '[{"min":11,"max":11}]';
        $schema['fields'] = [$row];
        $template = $converter->schemaToTemplate($schema);
        self::assertStringContainsString('"min": 11', $template);
        self::assertStringNotContainsString('"min": 2', $template);
    }

    public function test_invalid_raw_builder_arguments_remain_a_validation_error(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $row = $converter->emptyRow('value');
        $row['type'] = 'faker';
        $row['method'] = 'number.int';
        $row['args'] = 'not JSON';
        $schema = $converter->emptySchema();
        $schema['fields'] = [$row];

        $template = $converter->schemaToTemplate($schema);
        self::assertTrue(app(\App\Services\Templates\ResponseTemplateEngine::class)->validate($template)->hasErrors());
        self::assertNull($converter->templateToSchema($template));
    }

    public function test_fixed_strings_that_look_like_tokens_round_trip_as_literals(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $schema = $converter->emptySchema();
        $fakerLike = $converter->emptyRow('fakerLike');
        $fakerLike['value'] = '$person.firstName {{person.lastName}}';
        $interpolationLike = $converter->emptyRow('interpolationLike');
        $interpolationLike['value'] = 'Hello {{person.firstName}}';
        $schema['fields'] = [$fakerLike, $interpolationLike];

        $template = $converter->schemaToTemplate($schema);
        $rendered = app(\App\Services\Templates\ResponseTemplateEngine::class)->preview($template, 'en', 'fixed', 1)['output'];
        self::assertSame('$person.firstName {{person.lastName}}', $rendered->fakerLike);
        self::assertSame('Hello {{person.firstName}}', $rendered->interpolationLike);
        self::assertSame($template, $converter->schemaToTemplate($converter->templateToSchema($template)));
    }
}
