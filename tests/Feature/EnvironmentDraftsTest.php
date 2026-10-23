<?php

namespace Tests\Feature;

use App\Livewire\Admin\EnvironmentManager;
use App\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class EnvironmentDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_variable_save_reports_the_exact_row_and_preserves_input(): void
    {
        $environment = Environment::query()->sole();
        $variable = $environment->variables()->create(['key' => 'API_HOST', 'value' => 'original', 'is_secret' => false]);
        Livewire::test(EnvironmentManager::class)
            ->set("variableDrafts.{$variable->id}.key", '1bad')
            ->call('saveVariable', $variable->id)
            ->assertHasErrors(["variableDrafts.{$variable->id}.key" => 'regex'])
            ->assertSet("variableDrafts.{$variable->id}.key", '1bad')
            ->assertSee('field-error', false);
        self::assertSame('API_HOST', $variable->refresh()->key);
    }

    public function test_switching_and_saving_another_row_preserves_all_unsaved_drafts_without_loading_secrets(): void
    {
        $environment = Environment::query()->sole();
        $other = Environment::query()->create(['name' => 'Other']);
        $one = $environment->variables()->create(['key' => 'ONE', 'value' => 'one', 'is_secret' => false]);
        $two = $environment->variables()->create(['key' => 'TOKEN', 'value' => 'stored-secret', 'is_secret' => true]);
        Livewire::test(EnvironmentManager::class)
            ->assertDontSee('stored-secret')
            ->set('renameEnvironment', 'Unsaved name')
            ->set('newVariableKey', 'NEW')
            ->set("variableDrafts.{$one->id}.value", 'draft')
            ->set("variableDrafts.{$two->id}.value", 'replacement')
            ->call('selectEnvironment', $other->id)
            ->call('selectEnvironment', $environment->id)
            ->assertSet('renameEnvironment', 'Unsaved name')
            ->assertSet('newVariableKey', 'NEW')
            ->assertSet("variableDrafts.{$one->id}.value", 'draft')
            ->call('saveVariable', $two->id)
            ->assertHasNoErrors()
            ->assertSet("variableDrafts.{$two->id}.value", '')
            ->assertSet("environmentDrafts.{$environment->id}", null)
            ->assertSet("variableDrafts.{$one->id}.value", 'draft');
        self::assertSame('one', $one->refresh()->value);
        self::assertSame('replacement', $two->refresh()->value);
    }
}
