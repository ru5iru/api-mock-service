<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\EndpointIndex;
use App\Models\MockEndpoint;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tag_is_a_draft_until_endpoint_save_and_only_assigned_tags_appear_in_filters(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $editor = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])
            ->set('newTagName', 'abc')->call('createTagInline')
            ->assertDispatched('toast', message: 'Tag selected. Save changes to assign it to this endpoint.');
        $tag = Tag::query()->sole();
        $editor->assertSet('tagIds', [$tag->id]);
        self::assertFalse($endpoint->tags()->exists());
        Livewire::test(EndpointIndex::class)
            ->assertViewHas('filterTags', fn ($tags) => $tags->isEmpty())
            ->assertViewHas('availableTags', fn ($tags) => $tags->contains('id', $tag->id))
            ->assertDontSeeHtml('aria-label="Filter by tag abc"');

        $editor->call('save')->assertHasNoErrors();
        self::assertSame([$tag->id], $endpoint->tags()->pluck('tags.id')->all());
        Livewire::test(EndpointIndex::class)
            ->assertViewHas('filterTags', fn ($tags) => $tags->sole()->endpoints_count === 1)
            ->assertSeeHtml('aria-label="Filter by tag abc"');
    }

    public function test_tag_filter_matches_any_selected_tag_and_accepts_checkbox_string_ids(): void
    {
        $one = MockEndpoint::factory()->create(['name' => 'First tagged endpoint']);
        $two = MockEndpoint::factory()->create(['name' => 'Second tagged endpoint']);
        MockEndpoint::factory()->create(['name' => 'Untagged endpoint']);
        $a = Tag::query()->create(['name' => 'abc']);
        $b = Tag::query()->create(['name' => 'payments']);
        $one->tags()->attach($a);
        $two->tags()->attach($b);
        Livewire::test(EndpointIndex::class)
            ->set('tags', [(string) $a->id])->assertSet('tags', [$a->id])
            ->assertViewHas('endpoints', fn ($rows) => $rows->pluck('id')->all() === [$one->id])
            ->set('tags', [(string) $a->id, (string) $b->id])
            ->assertViewHas('endpoints', fn ($rows) => $rows->total() === 2)
            ->call('clearFilters')->assertViewHas('endpoints', fn ($rows) => $rows->total() === 3);
    }

    public function test_selected_unassigned_tag_can_still_be_cleared_and_bulk_assignment_remains_available(): void
    {
        $tag = Tag::query()->create(['name' => 'abc']);
        $endpoint = MockEndpoint::factory()->create();
        Livewire::test(EndpointIndex::class)->set('tags', [(string) $tag->id])
            ->assertSee('Clear tags')->assertViewHas('endpoints', fn ($rows) => $rows->total() === 0)
            ->set('tags', [])->assertViewHas('filterTags', fn ($tags) => $tags->isEmpty())
            ->set('selected', [$endpoint->id])->set('bulkTags', [$tag->id])->call('bulkAddTags')
            ->assertViewHas('filterTags', fn ($tags) => $tags->sole()->endpoints_count === 1);
        self::assertTrue($endpoint->tags()->whereKey($tag->id)->exists());
    }
}
