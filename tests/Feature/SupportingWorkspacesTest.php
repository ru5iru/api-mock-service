<?php

namespace Tests\Feature;

use App\Livewire\Admin\CallbackLog;
use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\CallbackAttempt;
use App\Models\MockEndpoint;
use App\Models\Revision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class SupportingWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_inspection_is_read_only_redacted_and_bounded_to_the_filter_window(): void
    {
        $response = MockEndpoint::factory()->create()->responses()->create([]);
        $attempt = CallbackAttempt::create([
            'response_id' => $response->id, 'target_url' => 'https://receiver.test/?token=unique-canary-123',
            'resolved_headers' => [], 'resolved_body' => '{}', 'method' => 'POST',
            'attempt_number' => 1, 'status' => 'failed', 'error' => 'Receiver unavailable',
        ]);
        $before = $attempt->fresh()->getAttributes();
        $revisions = Revision::count();
        $view = Livewire::test(CallbackLog::class)->call('inspectAttempt', $attempt->id)
            ->assertSee('Recorded target')->assertSee('Receiver unavailable')
            ->assertDontSee('unique-canary-123')->assertSee('Detail open');
        $view->set('search', 'no-such-request')->assertSee('no longer in the filtered window')
            ->assertDontSee('Receiver unavailable')->call('inspectAttempt', null)->assertDontSee('Back to list');
        self::assertSame($before, $attempt->fresh()->getAttributes());
        self::assertSame($revisions, Revision::count());
        self::assertSame(1, CallbackAttempt::count());
    }

    public function test_history_and_callback_keep_the_existing_editor_components(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $endpoint->responses()->create(['body' => 'unchanged']);
        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->assertSee('Endpoint history');
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('editCallback', $endpoint->responses()->sole()->id)->assertSee('callback-workspace', false)->assertSee('Save callback applies only these settings.');
        self::assertSame('unchanged', $endpoint->responses()->sole()->body);
    }

    public function test_supporting_routes_render_in_the_shared_shell(): void
    {
        foreach (['/dashboard/environments', '/dashboard/config', '/dashboard/docs', '/dashboard/logs?type=requests', '/dashboard/logs?type=callbacks'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/dashboard/does-not-exist')->assertNotFound();
    }

    public function test_recovery_shells_and_session_auth_do_not_replay_edits(): void
    {
        foreach ([404, 419, 429, 500, 503] as $status) {
            self::assertStringContainsString((string) $status, view('errors.'.$status)->render());
        }
        config(['mock.dashboard_auth.enabled' => true, 'mock.dashboard_auth.username' => 'operator', 'mock.dashboard_auth.password' => 'test-only-password']);
        $this->get('/dashboard')->assertRedirect('/dashboard/login');
        $this->get('/dashboard/login')->assertOk()->assertSee('Sign in');
    }
}
