<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Templates\TemplateContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ResponseAuthoringWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_sample_and_view_switches_leave_saved_configuration_and_runtime_untouched(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'literal {{token}}']);
        $before = $response->fresh()->getAttributes();
        $component = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $response->id)
            ->call('setBodyMode', 'template')
            ->call('setEditorView', 'json')
            ->set('template', '{"email":"$internet.email","name":"$person.firstName"}')
            ->set('locale', 'fr')
            ->set('seedMode', 'fixed')->set('seed', 42)
            ->call('previewTemplate')->assertHasNoErrors()
            ->assertSee(TemplateContext::PREVIEW_NOTICE)->assertSee('Sample data');
        $first = $component->get('previewOutput');
        self::assertNotFalse(filter_var(json_decode($first, true)['email'], FILTER_VALIDATE_EMAIL));
        $component->call('previewTemplate')->assertSet('previewOutput', $first)
            ->call('setEditorView', 'builder')->assertSet('editorView', 'builder')
            ->call('setEditorView', 'json')->call('setBodyMode', 'static')
            ->assertSet('body', 'literal {{token}}');
        self::assertSame($before, $response->fresh()->getAttributes());
        self::assertSame(0, Revision::query()->count());
        self::assertSame(0, EndpointCallState::query()->count());
    }

    public function test_nested_builder_edits_reorder_and_render_through_the_existing_converter(): void
    {
        $component = Livewire::test(ResponseManager::class, ['endpoint' => MockEndpoint::factory()->create()])
            ->call('setBodyMode', 'template')->call('setEditorView', 'json')
            ->set('template', '{"profile":{"first":"alpha","second":"beta"},"items":{"$repeat":2,"$item":1}}')
            ->call('setEditorView', 'builder')->assertSet('editorView', 'builder')
            ->call('moveSchemaRow', 'fields.0.children', 1, -1)
            ->assertSet('builderSchema.fields.0.children.0.key', 'second')
            ->call('previewTemplate')->assertHasNoErrors();
        self::assertSame(['second' => 'beta', 'first' => 'alpha'], json_decode($component->get('previewOutput'), true)['profile']);
        $component->call('setEditorView', 'json');
        self::assertSame(['$repeat' => 2, '$item' => 1], json_decode($component->get('template'), true)['items']);
    }

    public function test_invalid_template_keeps_source_and_clear_errors_and_context_stays_json_only(): void
    {
        $component = Livewire::test(ResponseManager::class, ['endpoint' => MockEndpoint::factory()->create()])
            ->call('setBodyMode', 'template')->call('setEditorView', 'json')
            ->set('template', '{"bad":"$person.notARealMethod"}')
            ->call('previewTemplate')->assertSet('previewOutput', '')
            ->assertSee('UNKNOWN_METHOD')->assertSee('Fix template errors before saving')
            ->call('save')->assertHasErrors(['template']);
        $source = '{"method":"{{$request.method}}"}';
        $component->set('template', $source)->assertSet('builderSupported', false)
            ->call('setEditorView', 'builder')->assertSet('editorView', 'json')
            ->assertSet('template', $source)->call('previewTemplate')
            ->assertSee(TemplateContext::PREVIEW_NOTICE);
        self::assertSame(0, Revision::query()->count());
        $this->assertDatabaseCount('mock_responses', 0);
    }
}
