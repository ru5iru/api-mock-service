<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointSectionValidityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_section_requires_exactly_one_fallback_and_tracks_drafts(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $a = $endpoint->responses()->create(['body' => 'first']);
        $b = $endpoint->responses()->create(['body' => 'second']);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $form->assertSeeHtml('section-status attention')->assertSee('Response selection needs attention');
        $a->update(['is_default' => true]);
        $form->call('$refresh')->assertSee('Response selection is complete');
        $b->update(['is_default' => true]);
        $form->call('$refresh')->assertSee('Response selection needs attention');
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('setFallback', $a->id)
            ->assertDispatched('response-selection-validity', endpointId: $endpoint->id, valid: true);
        $form->call('updateResponseValidity', $endpoint->id, true)->assertSee('Response selection is complete');
        $form->call('updateResponseValidity', $endpoint->id + 1, false)->assertSee('Response selection is complete');
    }

    public function test_request_and_matching_states_are_driven_by_real_input(): void
    {
        Livewire::test(EndpointForm::class)->call('touchSection', 'matching')
            ->assertSee('Matching policy needs attention')
            ->set('rawCurl', "curl 'https://example.test/validity'")
            ->assertSee('Matching policy is ready')->assertSee('Request is valid')
            ->set('priority', 1001)->assertDontSee('Request is valid')
            ->set('priority', 0)->assertSee('Request is valid');
    }
}
