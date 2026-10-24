<?php

namespace Tests\Feature;

use App\Livewire\Admin\CallbackLog;
use App\Models\CallbackAttempt;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CallbackLogEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_uses_recorded_target_and_sanitized_error_instead_of_current_configuration(): void
    {
        $response = MockEndpoint::factory()->create()->responses()->create(['callback_url' => 'https://new.example.test/hook']);
        CallbackAttempt::query()->create([
            'response_id' => $response->id, 'target_url' => 'https://old.example.test/hook?token=hidden-target-secret',
            'resolved_headers' => [], 'resolved_body' => '{}',
            'method' => 'POST', 'attempt_number' => 1, 'status' => 'failed', 'http_status' => 500,
            'error' => 'Receiver failed. Authorization: Bearer hidden-error-secret',
        ]);
        Livewire::test(CallbackLog::class)->assertSee('old.example.test')->assertDontSee('new.example.test')
            ->assertSee('Receiver failed.')->assertSee('[REDACTED]')->assertDontSee('hidden-target-secret')
            ->assertDontSee('hidden-error-secret')->assertSee('captured target and payload')
            ->set('search', 'old.example.test')->assertSee('Attempt #');
    }

    public function test_deep_link_scopes_are_visible_and_independently_removable(): void
    {
        Livewire::test(CallbackLog::class)->set('responseId', '123')->set('requestLogId', 'request-abc')
            ->assertSee('Response #123')->assertSee('Request request-abc')
            ->set('responseId', '')->assertDontSee('Response #123')->assertSee('Request request-abc');
    }
}
