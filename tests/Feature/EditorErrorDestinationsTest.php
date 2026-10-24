<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Livewire\Admin\RevisionHistory;
use App\Models\MockEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EditorErrorDestinationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_errors_identify_response_condition_and_repair_control(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'a']);
        $b = $endpoint->responses()->create(['body' => 'b']);
        $path = 'responseRules.'.$a->id.'.0.value';
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])
            ->call('setSelectionMode', 'rule')->call('setFallback', $b->id)
            ->set('responseRules.'.$a->id, [['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'regex', 'value' => '[invalid', 'priority' => 0]])
            ->call('saveSelection')->assertHasErrors($path)
            ->assertSee('Response #'.$a->id.', condition 1:')
            ->assertSeeHtml('data-error-for="'.$path.'"')
            ->assertSeeHtml('data-focus-error="'.$path.'"');
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
    }

    public function test_response_history_comparisons_have_distinct_heading_ids(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'a']);
        $b = $endpoint->responses()->create(['body' => 'b']);
        foreach ([$a, $b] as $response) {
            Livewire::test(RevisionHistory::class, ['entityType' => 'response', 'entityId' => $response->id])
                ->set('diff', ['from' => ['version' => 1], 'to' => ['kind' => 'current'], 'changes' => []])
                ->assertSeeHtml('id="revision-diff-heading-response-'.$response->id.'-ordinary"');
        }
    }

    public function test_failed_request_and_matching_saves_reveal_the_affected_tab(): void
    {
        Livewire::test(EndpointForm::class)->set('activeTab', 'callback')
            ->set('rawCurl', 'not a curl')->call('save')->assertHasErrors('rawCurl')
            ->assertSet('activeTab', 'request');
        Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'https://example.test/{bad-name}'")
            ->set('pathPatternEnabled', true)->set('activeTab', 'response')->call('save')
            ->assertHasErrors('pathPatternEnabled')->assertSet('activeTab', 'matching');
        self::assertSame(0, MockEndpoint::query()->count());
    }

    public function test_header_only_exclusions_do_not_change_the_query_badge(): void
    {
        Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'https://example.test/items?status=ready' -H 'X-Mode: test'")
            ->set('excludedHeaderNames', ['x-mode'])->assertSee('Always included')
            ->set('excludedQueryParams', ['status'])->assertSee('1 of 1 excluded');
    }
}
