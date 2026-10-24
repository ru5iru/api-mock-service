<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EditorSequenceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_loop_repeat_and_exhausted_positions_are_truthful_without_writing_state(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->assertSee('No responses configured')->assertDontSee('1 of 0');
        $endpoint->responses()->create(['body' => 'one', 'sequence_order' => 0]);
        $endpoint->responses()->create(['body' => 'two', 'sequence_order' => 1]);
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id,
            'environment_id' => app(EnvironmentContext::class)->active()->id, 'sequence_position' => 5, 'total_match_count' => 8]);
        $before = $state->fresh()->getRawOriginal();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->assertSee('Next response: 2 of 2')->assertSee('Matched calls: 8.')->assertDontSee('6 of 2');
        $editor->set('sequenceOnExhaust', 'repeat_last')->assertSee('Next response: 2 of 2');
        $editor->set('sequenceOnExhaust', 'not_found')->assertSee('Sequence exhausted');
        self::assertSame($before, $state->fresh()->getRawOriginal());
    }
}
