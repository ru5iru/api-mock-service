<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class BuilderDuplicateKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_names_block_saving_without_discarding_rows(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $schema = $converter->emptySchema();
        $schema['fields'] = [$converter->emptyRow('id'), $converter->emptyRow(' id ')];
        $endpoint = MockEndpoint::factory()->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('setBodyMode', 'template')->set('builderSchema', $schema)
            ->assertHasErrors(['builderSchema.fields.0.key', 'builderSchema.fields.1.key'])
            ->assertSet('builderSchema.fields.1.key', ' id ')->call('save')
            ->assertHasErrors('builderSchema.fields.1.key');
        self::assertSame(0, $endpoint->responses()->count());
        $editor->set('builderSchema.fields.1.key', 'other')->call('save')->assertHasNoErrors();
        self::assertCount(2, json_decode($endpoint->responses()->sole()->template, true));
    }

    public function test_nested_callback_objects_use_precise_paths_and_allow_same_names_in_other_objects(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $nested = $converter->emptyRow('items');
        $nested['type'] = 'array';
        $nested['item'] = ['type' => 'object', 'children' => [$converter->emptyRow('id'), $converter->emptyRow('id')]];
        $schema = ['fields' => [$nested, $converter->emptyRow('id')]];
        try {
            $converter->schemaToTemplate($schema, 'callbackBuilderSchema');
            self::fail('Duplicate item properties must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('callbackBuilderSchema.fields.0.item.children.0.key', $exception->errors());
            self::assertArrayHasKey('callbackBuilderSchema.fields.0.item.children.1.key', $exception->errors());
            self::assertArrayNotHasKey('callbackBuilderSchema.fields.1.key', $exception->errors());
        }
        $schema['fields'][0]['item']['children'][1]['key'] = 'other';
        self::assertIsString($converter->schemaToTemplate($schema));
    }

    public function test_callback_save_cannot_bypass_duplicate_builder_validation(): void
    {
        $converter = app(TemplateSchemaConverter::class);
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'saved', 'callback_body' => '{"original":true}']);
        $schema = ['fields' => [$converter->emptyRow('id'), $converter->emptyRow('id')]];
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('edit', $response->id)
            ->call('setCallbackEditorView', 'builder')->set('callbackBuilderSchema', $schema)
            ->call('saveCallback')->assertHasErrors('callbackBuilderSchema.fields.1.key')
            ->assertSet('callbackBuilderSchema.fields.0.key', 'id');
        self::assertSame('{"original":true}', $response->fresh()->callback_body);
    }
}
