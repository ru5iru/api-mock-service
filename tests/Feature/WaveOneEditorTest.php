<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Environments\EnvironmentContext;
use App\Services\Response\ResponseSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

final class WaveOneEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_save_requires_one_fallback_and_fallback_switch_is_atomic(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('setSelectionMode', 'rule')->call('saveSelection')->assertHasErrors('is_default');
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
        $editor->call('setFallback', $a->id)->call('saveSelection')->assertHasNoErrors();
        $editor->call('setFallback', $b->id)->call('saveSelection')->assertHasNoErrors();
        self::assertFalse($a->fresh()->is_default);
        self::assertTrue($b->fresh()->is_default);
        self::assertSame(1, $endpoint->responses()->where('is_default', true)->count());
        $editor->call('delete', $b->id)->assertHasErrors('selection_mode');
        self::assertNotNull($b->fresh());
        $this->delete('/dashboard/responses/'.$b->id)->assertUnprocessable();
    }

    public function test_sequence_drag_adapter_persists_order_and_reset_is_environment_scoped(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('setSelectionMode', 'sequence')
            ->call('reorderSchemaRow', 'responses', 0, 1, 'selection')
            ->set('sequenceOnExhaust', 'loop')->call('saveSelection')->assertHasNoErrors();
        self::assertSame(0, $b->fresh()->sequence_order);
        self::assertSame(1, $a->fresh()->sequence_order);
        $active = app(EnvironmentContext::class)->active();
        $other = Environment::query()->create(['name' => 'Other', 'is_default' => false]);
        $selector = app(ResponseSelectionService::class);
        $selector->select($endpoint->fresh(), Request::create('/'), $active);
        $selector->select($endpoint->fresh(), Request::create('/'), $other);
        $editor->call('resetSequence')->assertHasNoErrors();
        self::assertSame(0, $endpoint->callStates()->where('environment_id', $active->id)->sole()->sequence_position);
        self::assertSame(1, $endpoint->callStates()->where('environment_id', $other->id)->sole()->sequence_position);
        self::assertSame(1, $endpoint->callStates()->where('environment_id', $active->id)->sole()->total_match_count);
    }

    public function test_conditions_persist_and_ordering_sets_global_priorities(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $fallback = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('setSelectionMode', 'rule')->call('setFallback', $fallback->id)
            ->call('addRule', $a->id)->call('addRule', $b->id)
            ->set('responseRules.'.$a->id.'.0.field_name', 'X-Mode')
            ->set('responseRules.'.$a->id.'.0.value', 'yes')
            ->set('responseRules.'.$b->id.'.0.field_name', 'X-Mode')
            ->set('responseRules.'.$b->id.'.0.value', 'yes')
            ->call('reorderSchemaRow', 'responses', 0, 1, 'selection')
            ->call('saveSelection')->assertHasNoErrors();
        self::assertTrue($b->rules()->sole()->priority < $a->rules()->sole()->priority);
        $chosen = app(ResponseSelectionService::class)->select($endpoint->fresh(), Request::create('/', 'GET', [], [], [], ['HTTP_X_MODE' => 'yes']), app(EnvironmentContext::class)->active());
        self::assertSame($b->id, $chosen->response->id);
    }

    public function test_sequence_delete_renumbers_and_add_response_appends(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'repeat_last']);
        $a = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0]);
        $b = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 1]);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('delete', $a->id)->assertHasNoErrors();
        self::assertSame(0, $b->fresh()->sequence_order);
        $editor->call('save')->assertHasNoErrors();
        self::assertSame([0, 1], $endpoint->responses()->pluck('sequence_order')->all());
    }
}
