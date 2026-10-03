<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointSaveDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_save_waits_for_response_drafts_before_redirecting(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'old']);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $form->set('name', 'Changed endpoint')->call('saveAll')
            ->assertSet('savingAll', true)->assertNoRedirect()
            ->assertDispatched('save-response-drafts', endpointId: $endpoint->id);
        self::assertNotSame('Changed endpoint', $endpoint->fresh()->name);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('edit', $response->id)
            ->set('body', 'new')->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors()
            ->assertDispatched('response-drafts-saved', endpointId: $endpoint->id);
        self::assertSame('new', $response->fresh()->body);
        $form->call('finishSavingAll', $endpoint->id)->assertRedirect(route('dashboard.endpoints.edit', $endpoint));
        self::assertSame('Changed endpoint', $endpoint->fresh()->name);
    }

    public function test_unchanged_add_form_does_not_create_response_and_direct_save_resets_baseline(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('savePendingDrafts', $endpoint->id)->assertDispatched('response-drafts-saved');
        self::assertSame(0, $endpoint->responses()->count());
        $editor->set('body', 'new response')->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        self::assertSame(1, $endpoint->responses()->count());
        $editor->call('savePendingDrafts', $endpoint->id);
        self::assertSame(1, $endpoint->responses()->count());
    }

    public function test_invalid_response_blocks_global_save_and_preserves_selection_drafts(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'old']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('edit', $response->id)->set('body', 'unsaved')->set('weight', 0)
            ->call('setSelectionMode', 'sequence')->call('savePendingDrafts', $endpoint->id)
            ->assertHasErrors('weight')->assertSet('body', 'unsaved')->assertSet('selectionMode', 'sequence')
            ->assertSee('Response changes were not saved.')->assertDispatched('response-drafts-save-failed');
        self::assertSame('old', $response->fresh()->body);
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
        $editor->set('weight', 1)->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        self::assertSame('unsaved', $response->fresh()->body);
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->call('saveAll')
            ->call('responseDraftsFailed', $endpoint->id)->assertSet('savingAll', false)->assertNoRedirect();
    }

    public function test_empty_header_object_saves_but_header_array_is_rejected(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->set('headersJson', '[]')->call('save')->assertHasErrors('headersJson');
        self::assertSame(0, $endpoint->responses()->count());
        $editor->set('headersJson', '{}')->call('save')->assertHasNoErrors();
        self::assertSame([], $endpoint->responses()->sole()->headers);
        $editor->call('edit', $endpoint->responses()->sole()->id)->set('body', 'edited with empty headers')
            ->call('save')->assertHasNoErrors();
        self::assertSame('edited with empty headers', $endpoint->responses()->sole()->body);
    }

    public function test_global_save_persists_selection_only_without_creating_response(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $endpoint->responses()->create(['body' => 'only response']);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('setSelectionMode', 'sequence')->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        self::assertSame(1, $endpoint->responses()->count());
    }
}
