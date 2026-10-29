<?php

namespace Tests\Feature;

use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use App\Services\Response\ResponseSelectionService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

final class SelectionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_weight_chances_describe_saved_pool_until_a_response_is_saved(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['weight' => 1, 'body' => 'first']);
        $endpoint->responses()->create(['weight' => 3, 'body' => 'second']);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->assertSee('25.0% of saved pool')->assertSee('75.0% of saved pool')
            ->call('edit', $a->id)->set('weight', 99)
            ->assertSee('25.0% of saved pool')->assertSee('Save response weight edits');
        self::assertSame(1, $a->fresh()->weight);
        $editor->call('save')->assertHasNoErrors()->assertSee('97.1% of saved pool');
    }

    public function test_selection_navigation_and_preview_do_not_advance_runtime_and_rules_are_outside_metadata(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence']);
        $a = $endpoint->responses()->create(['body' => 'first', 'sequence_order' => 0]);
        $endpoint->responses()->create(['body' => 'second', 'sequence_order' => 1]);
        $environment = app(EnvironmentContext::class)->active();
        app(ResponseSelectionService::class)->select($endpoint, Request::create('/'), $environment);
        $before = $endpoint->callStates()->sole()->getAttributes();
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->assertSee('Next response: 2 of 2')->call('setSelectionMode', 'rule')->call('addRule', $a->id);
        $dom = new DOMDocument;
        @$dom->loadHTML($editor->html());
        $xpath = new DOMXPath($dom);
        self::assertSame(0, $xpath->query('//details[contains(@class,"response-row-details")]//*[contains(@class,"selection-response-details")]')->length);
        self::assertSame(2, $xpath->query('//article[contains(@class,"selection-response-card")]/div[contains(@class,"selection-response-details")]')->length);
        self::assertSame($before, $endpoint->callStates()->sole()->getAttributes());
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
    }

    public function test_fault_summary_is_draft_only_and_the_existing_enum_rejects_unknown_types(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = $endpoint->responses()->create(['body' => '{"ok":true}', 'headers' => ['Content-Type' => 'application/json']]);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('edit', $response->id)->set('faultEnabled', true)->set('faultType', 'delay')
            ->set('faultProbability', 41)->set('faultDelayMsMin', 17)
            ->assertSee('Delay ·')->assertSeeHtml('>41</span>%')->assertSee('Update response or Save changes applies these settings.');
        self::assertFalse($response->fresh()->fault_enabled);
        $editor->set('faultType', 'connection_reset')->call('save')->assertHasErrors('faultType');
        self::assertFalse($response->fresh()->fault_enabled);
    }
}
