<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_selection_status_tracks_actual_drafts_and_save_without_changing_response_content(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['status_code' => 200, 'body' => 'recognizable body', 'external_label' => 'Saved example date']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->assertSee('Selection saved.')->assertDontSee('Unsaved selection changes.')
            ->assertSee('Response #'.$response->id)->assertSee('Saved example date')->assertSee('recognizable body')
            ->call('setSelectionMode', 'sequence')->assertSee('Unsaved selection changes.')
            ->call('saveSelection')->assertHasNoErrors()->assertSee('Selection saved.')->assertDontSee('Unsaved selection changes.');
        self::assertSame('recognizable body', $response->refresh()->body);
        self::assertSame('sequence', $endpoint->refresh()->selection_mode);
    }

    public function test_session_recovery_is_an_explicit_get_link_and_post_errors_do_not_offer_replay(): void
    {
        $html = view('errors.419')->render();
        self::assertStringContainsString('Refresh session', $html);
        self::assertStringContainsString(route('dashboard.login'), $html);
        request()->setMethod('POST');
        self::assertStringNotContainsString('Try again', view('errors.500')->render());
    }
}
