<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointSelectionDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_response_saves_preserve_mode_order_and_exhaustion_drafts(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'a']);
        $b = $endpoint->responses()->create(['body' => 'b']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('setSelectionMode', 'sequence')->set('sequenceOnExhaust', 'loop')
            ->set('responseOrder', [$b->id, $a->id])->call('edit', $a->id)
            ->set('body', 'changed')->call('save')->assertHasNoErrors()
            ->assertSet('selectionMode', 'sequence')->assertSet('sequenceOnExhaust', 'loop')
            ->assertSet('responseOrder', [$b->id, $a->id])->assertSee('Unsaved selection changes.');
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
        self::assertSame('changed', $a->fresh()->body);
        $editor->set('body', 'new')->call('save')->assertHasNoErrors();
        $newId = $endpoint->responses()->max('id');
        $editor->assertSet('responseOrder', [$b->id, $a->id, $newId])
            ->call('delete', $a->id)->assertSet('responseOrder', [$b->id, $newId])
            ->assertSet('selectionMode', 'sequence')->call('saveSelection')->assertHasNoErrors();
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        self::assertSame(0, $b->fresh()->sequence_order);
    }

    public function test_local_response_save_preserves_rule_fallback_and_conditions(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'a']);
        $b = $endpoint->responses()->create(['body' => 'b']);
        $rules = [['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'test', 'priority' => 0]];
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('setSelectionMode', 'rule')->call('setFallback', $b->id)
            ->set("responseRules.{$a->id}", $rules)->call('edit', $a->id)
            ->set('body', 'new')->call('save')->assertHasNoErrors()
            ->assertSet('selectionMode', 'rule')->assertSet('fallbackResponseId', $b->id)
            ->assertSet("responseRules.{$a->id}", $rules)->call('saveSelection')->assertHasNoErrors();
        self::assertTrue($b->fresh()->is_default);
        self::assertSame('X-Mode', $a->rules()->sole()->field_name);
    }
}
