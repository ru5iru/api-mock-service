<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class CallbackTestProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_changed_target_cannot_queue_a_test_until_callback_is_saved(): void
    {
        Queue::fake();
        $response = MockResponse::factory()->create(['callback_enabled' => true, 'callback_url' => 'https://old.example.test/hook']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('editCallback', $response->id)->set('callbackUrl', 'https://new.example.test/hook')
            ->call('sendTestCallback')->assertHasErrors('callbackEnabled')->assertSet('callbackTestId', null)
            ->assertSee('Save callback to test these changes.');
        app()->terminate();
        Queue::assertNothingPushed();
        self::assertSame('https://old.example.test/hook', $response->fresh()->callback_url);
        $editor->call('saveCallback')->assertHasNoErrors()->call('sendTestCallback')->assertHasNoErrors();
        app()->terminate();
        Queue::assertPushed(DeliverCallback::class, fn ($job) => $job->responseId === $response->id);
        self::assertSame('https://new.example.test/hook', $response->fresh()->callback_url);
    }

    public function test_preview_only_and_response_body_drafts_do_not_block_a_saved_callback_test(): void
    {
        $response = MockResponse::factory()->create(['callback_enabled' => true, 'callback_url' => 'https://example.test/hook?token=private-value']);
        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('editCallback', $response->id)->set('body', 'unrelated response draft')
            ->assertSet('callbackPreview', '')->assertSee('Test saved callback')
            ->assertSee('Saved target:')->assertSee('[REDACTED]');
    }
}
