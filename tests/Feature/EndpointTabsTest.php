<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_discards_only_the_selected_response_draft(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'original A']);
        $b = $endpoint->responses()->create(['body' => 'original B']);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $a->id)->set('body', 'draft A')
            ->call('edit', $b->id)->set('body', 'cancelled B')->call('cancelEdit')
            ->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        $this->assertSame('draft A', $a->fresh()->body);
        $this->assertSame('original B', $b->fresh()->body);
    }

    public function test_restoring_an_inactive_response_clears_its_cached_draft(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'original A']);
        $b = $endpoint->responses()->create(['body' => 'original B']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $a->id)->set('body', 'stale A')->call('edit', $b->id)->set('body', 'draft B');
        $a->update(['body' => 'restored A']);
        $editor->call('revisionRestored', 'response', $a->id)->call('edit', $a->id)->assertSet('body', 'restored A')
            ->call('edit', $b->id)->assertSet('body', 'draft B');
    }

    public function test_response_and_callback_drafts_survive_response_selection_and_global_save(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'original A']);
        $b = $endpoint->responses()->create(['body' => 'original B']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('edit', $a->id)->set('body', 'draft A')
            ->call('editCallback', $b->id, true)->set('callbackUrl', 'https://receiver.test/b')->set('callbackBody', '{"event":"b"}')
            ->call('edit', $a->id)->assertSet('body', 'draft A')
            ->call('editCallback', $b->id)->assertSet('callbackUrl', 'https://receiver.test/b')
            ->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        $this->assertSame('draft A', $a->fresh()->body);
        $this->assertSame('original B', $b->fresh()->body);
        $this->assertSame('https://receiver.test/b', $b->fresh()->callback_url);
        $this->assertTrue($b->fresh()->callback_enabled);
    }

    public function test_callback_save_changes_only_callback_fields_and_preserves_primary_response_draft(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => 'original']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('edit', $response->id)->set('body', 'draft response')->call('editCallback', $response->id, true)
            ->set('callbackUrl', 'https://receiver.test/hook')->call('saveCallback')->assertHasNoErrors()->assertSet('body', 'draft response');
        $this->assertSame('original', $response->fresh()->body);
        $this->assertSame('https://receiver.test/hook', $response->fresh()->callback_url);
        $editor->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        $this->assertSame('draft response', $response->fresh()->body);
    }

    public function test_cached_invalid_callback_keeps_the_tab_incomplete_and_blocks_atomic_global_save(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'original']);
        $b = $endpoint->responses()->create(['body' => 'other']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('editCallback', $a->id, true)->set('callbackUrl', '')
            ->call('editCallback', $b->id)->call('publishCallbackValidity')->assertDispatched('callback-draft-validity', valid: false)
            ->set('body', 'draft other')->call('savePendingDrafts', $endpoint->id)->assertHasErrors('callbackUrl');
        $this->assertSame('other', $b->fresh()->body);
        $this->assertFalse($a->fresh()->callback_enabled);
    }

    public function test_switching_tasks_keeps_request_drafts_and_renders_four_linked_tabs(): void
    {
        $form = Livewire::test(EndpointForm::class)->set('priority', 77)->set('rawCurl', "curl 'https://example.test/draft'");
        foreach (['matching', 'response', 'callback', 'request'] as $tab) {
            $form->set('activeTab', $tab)->call('touchSection', $tab)->assertSet('priority', 77)->assertSet('rawCurl', "curl 'https://example.test/draft'");
        }
        $form->assertSeeHtml('role="tablist"')->assertSeeHtml('aria-controls="panel-callback"')->assertSeeHtml('data-editor-viewport');
    }

    public function test_title_rename_is_independent_of_other_request_drafts_and_blank_restores_derived_name(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Original', 'method' => 'POST', 'normalized_curl' => "POST\n/title\n\n", 'priority' => 0]);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->set('priority', 50);
        $form->call('renameEndpoint', '  Renamed  ')->assertSet('name', 'Renamed');
        $this->assertSame('Renamed', $endpoint->fresh()->name);
        $this->assertSame(0, $endpoint->fresh()->priority);
        $form->call('renameEndpoint', '')->assertSet('name', '');
        $this->assertSame('POST /title', $endpoint->fresh()->displayName());
        $form->call('renameEndpoint', str_repeat('x', 256))->assertHasErrors('name');
        $this->assertNull($endpoint->fresh()->name);
    }
}
