<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointIndex;
use App\Models\Collection;
use App\Models\MockEndpoint;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RegistryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_states_distinguish_database_collection_and_filtered_results(): void
    {
        Livewire::test(EndpointIndex::class)->assertSeeHtml('data-registry-empty="database"')->assertSee('Your mock registry is empty');
        MockEndpoint::factory()->create();
        $collection = Collection::query()->create(['name' => 'Empty collection']);
        Livewire::test(EndpointIndex::class)->set('collection', (string) $collection->id)
            ->assertSeeHtml('data-registry-empty="collection"')->assertSee('This collection has no endpoints')
            ->set('search', 'missing')->assertSeeHtml('data-registry-empty="filtered"')
            ->assertSee('No endpoints match these filters')->call('clearFilters')
            ->assertViewHas('endpoints', fn ($rows) => $rows->total() === 1);
    }

    public function test_filter_sort_counts_and_pagination_keep_the_same_query_contract(): void
    {
        $collection = Collection::query()->create(['name' => 'Payments']);
        $tag = Tag::query()->create(['name' => 'retry']);
        $wanted = MockEndpoint::factory()->create(['name' => 'Alpha payment', 'method' => 'POST', 'collection_id' => $collection->id, 'priority' => 7]);
        $wanted->tags()->attach($tag);
        MockEndpoint::factory()->count(17)->create(['enabled' => false]);
        Livewire::test(EndpointIndex::class)
            ->assertViewHas('endpoints', fn ($rows) => $rows->count() === 15 && $rows->total() === 18)
            ->call('gotoPage', 2)->assertViewHas('endpoints', fn ($rows) => $rows->count() === 3)
            ->set('search', 'payment')->assertViewHas('endpoints', fn ($rows) => $rows->currentPage() === 1 && $rows->total() === 1)
            ->set('method', 'POST')->set('state', 'enabled')->set('collection', (string) $collection->id)
            ->set('tags', [(string) $tag->id])->set('sort', 'priority')
            ->assertSee('1 of 18 endpoints')->assertViewHas('endpoints', fn ($rows) => $rows->sole()->id === $wanted->id)
            ->call('clearFilters')->assertSet('sort', 'recent')->assertSet('tags', [])
            ->assertViewHas('endpoints', fn ($rows) => $rows->total() === 18)
            ->set('sort', 'name')->assertViewHas('endpoints', fn ($rows) => $rows->first()->id === $wanted->id)
            ->set('sort', 'priority')->assertViewHas('endpoints', fn ($rows) => $rows->first()->id === $wanted->id);
    }

    public function test_selection_spans_pages_and_filters_and_all_bulk_operations_keep_their_contracts(): void
    {
        MockEndpoint::factory()->count(18)->create(['enabled' => false]);
        $collection = Collection::query()->create(['name' => 'Bulk destination']);
        $tag = Tag::query()->create(['name' => 'bulk-tag']);
        $component = Livewire::test(EndpointIndex::class)->set('sort', 'name')->call('selectPage')
            ->assertSee('15 selected')->call('gotoPage', 2)->call('selectPage')->assertSee('18 selected')
            ->set('search', 'no-such-endpoint')->assertSee('0 on this page. Selection is kept across pages and filters.')
            ->call('bulkSetEnabled', true)->assertSet('selected', []);
        self::assertSame(18, MockEndpoint::query()->where('enabled', true)->count());
        $ids = MockEndpoint::query()->limit(2)->pluck('id')->all();
        $component->call('clearFilters')->set('selected', $ids)->set('bulkCollection', (string) $collection->id)
            ->call('bulkMoveToCollection')->assertSet('selected', []);
        self::assertSame(2, $collection->endpoints()->count());
        $component->set('selected', $ids)->set('bulkTags', [$tag->id])->call('bulkAddTags')->assertSet('selected', []);
        self::assertSame(2, $tag->endpoints()->count());
        $component->set('selected', $ids)->call('bulkExport')->assertFileDownloaded()
            ->call('bulkSetEnabled', false)->assertSet('selected', []);
        self::assertSame(2, MockEndpoint::query()->where('enabled', false)->count());
        $component->set('selected', $ids)->call('bulkDelete')->assertSet('selected', []);
        self::assertSame(16, MockEndpoint::query()->count());
    }

    public function test_individual_row_actions_and_secondary_activity_remain_accessible(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Registry action target']);
        $endpoint->responses()->create(['body' => 'original']);
        Livewire::test(EndpointIndex::class)
            ->assertSeeHtml('More actions for Registry action target')->assertSeeHtml('Copy mock curl for Registry action target')
            ->call('toggleEnabled', $endpoint->id)->assertDispatched('toast', message: 'Registry action target disabled.')
            ->call('duplicate', $endpoint->id)->assertHasNoErrors();
        $copy = MockEndpoint::query()->whereKeyNot($endpoint->id)->sole();
        self::assertFalse($copy->enabled);
        self::assertSame('original', $copy->responses()->sole()->body);
        Livewire::test(EndpointIndex::class)->set('selected', [$copy->id])->call('delete', $copy->id)->assertSet('selected', []);
        self::assertTrue($endpoint->fresh()->exists);
        $this->get('/dashboard')->assertOk()->assertSeeHtml('class="registry-activity section-spacer" data-disclosure open')
            ->assertSeeHtml('aria-controls="registry-recent-requests"');
    }
}
