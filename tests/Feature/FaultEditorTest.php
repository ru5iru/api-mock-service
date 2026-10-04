<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FaultEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_responses_default_to_disabled_faults_and_editor_saves_each_field(): void
    {
        $response = MockResponse::factory()->create();
        self::assertFalse($response->fresh()->fault_enabled);
        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)->set('faultEnabled', true)->set('faultType', 'delay')
            ->set('faultDelayMsMin', 20)->set('faultDelayMsMax', 50)->set('faultProbability', 42)
            ->call('save')->assertHasNoErrors()->assertSet('faultEnabled', false);
        $saved = $response->fresh();
        self::assertTrue($saved->fault_enabled);
        self::assertSame('delay', $saved->fault_type);
        self::assertSame(20, $saved->fault_delay_ms_min);
        self::assertSame(50, $saved->fault_delay_ms_max);
        self::assertSame(42, $saved->fault_probability);
        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)->assertSet('faultEnabled', true)->assertSet('faultProbability', 42)
            ->assertSet('faultDelayMsMin', 20)->assertSet('faultDelayMsMax', 50);
    }

    public function test_fault_drafts_survive_response_switches_and_global_save(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $a->id)->set('faultEnabled', true)->set('faultType', 'malformed_body')
            ->call('edit', $b->id)->set('faultEnabled', true)->set('faultType', 'truncated_body')
            ->call('edit', $a->id)->assertSet('faultType', 'malformed_body')
            ->call('savePendingDrafts', $endpoint->id)->assertHasNoErrors();
        self::assertSame('malformed_body', $a->fresh()->fault_type);
        self::assertSame('truncated_body', $b->fresh()->fault_type);
        self::assertTrue($a->fresh()->fault_enabled);
        self::assertTrue($b->fresh()->fault_enabled);
    }

    public function test_invalid_fault_draft_blocks_atomic_save_and_displays_inline_errors(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $a->id)->set('body', 'unsaved change')
            ->call('edit', $b->id)->set('faultEnabled', true)->set('faultDelayMsMin', 500)->set('faultDelayMsMax', 50)
            ->call('savePendingDrafts', $endpoint->id)->assertHasErrors('faultDelayMsMax')
            ->assertSee('Fault delay max must be at least the minimum delay.');
        self::assertNotSame('unsaved change', $a->fresh()->body);
        self::assertFalse($b->fresh()->fault_enabled);
    }

    public function test_malformed_body_rejects_plain_text_and_fault_probability_is_validated(): void
    {
        $response = MockResponse::factory()->create();
        Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)->set('faultEnabled', true)->set('faultType', 'malformed_body')
            ->set('headersJson', '{"Content-Type":"text/plain"}')
            ->call('save')->assertHasErrors('faultType')
            ->set('faultType', 'truncated_body')->set('faultProbability', 101)
            ->call('save')->assertHasErrors('faultProbability');
        self::assertFalse($response->fresh()->fault_enabled);
    }

    public function test_callback_save_does_not_persist_or_drop_primary_fault_draft(): void
    {
        $response = MockResponse::factory()->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $response->endpoint])
            ->call('edit', $response->id)->set('faultEnabled', true)->set('faultType', 'timeout')->set('faultDelayMsMin', 60000)
            ->call('editCallback', $response->id, true)->set('callbackUrl', 'https://receiver.test/hook')
            ->call('saveCallback')->assertHasNoErrors()->assertSet('faultType', 'timeout');
        self::assertFalse($response->fresh()->fault_enabled);
        $editor->call('savePendingDrafts', $response->mock_endpoint_id)->assertHasNoErrors();
        self::assertTrue($response->fresh()->fault_enabled);
        self::assertSame(60000, $response->fresh()->fault_delay_ms_min);
    }
}
