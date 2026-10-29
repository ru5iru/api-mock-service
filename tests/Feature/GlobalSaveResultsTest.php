<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\Collection;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class GlobalSaveResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($expected = getenv('AUDIT_DB')) {
            self::assertSame('pgsql', DB::getDriverName());
            self::assertSame($expected, DB::selectOne('select current_database() as name')->name);
        }
    }

    private function fixture(): array
    {
        $endpoint = app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: "curl 'http://example.test/global-save'"));
        $a = $endpoint->responses()->create(['body' => 'before-a']);
        $b = $endpoint->responses()->create(['body' => 'before-b']);

        return [$endpoint, $a, $b, Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]), Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])];
    }

    private function childSave($form, $editor, $endpoint): string
    {
        $form->call('saveAll')->assertHasNoErrors()->assertSet('savingAll', true);
        $id = $form->get('saveAttempt')['id'];
        $editor->dispatch('save-response-drafts', endpointId: $endpoint->id, attemptId: $id);

        return $id;
    }

    private function finish($form, $editor, $endpoint, string $id): void
    {
        $form->dispatch('response-drafts-saved', endpointId: $endpoint->id, attemptId: $id, scopes: $editor->get('globalSaveScopes'));
    }

    public function test_all_scopes_commit_and_individual_drafts_remain_unchanged_until_commit(): void
    {
        [$endpoint, $a, $b, $form, $editor] = $this->fixture();
        $collection = Collection::create(['name' => 'Draft collection']);
        $form->set('priority', 5)->set('collectionId', (string) $collection->id);
        $editor->call('edit', $a->id)->set('body', 'after-a')->set('callbackUrl', 'https://receiver.test/delivery')
            ->call('edit', $b->id)->set('body', 'after-b')->call('setSelectionMode', 'sequence');
        $id = $this->childSave($form, $editor, $endpoint);
        $editor->assertHasNoErrors()->assertNotDispatched('toast')->assertSet('pendingFormDrafts', []);
        foreach (['selection', 'response:'.$a->id, 'callback:'.$a->id, 'response:'.$b->id] as $key) {
            self::assertSame('Saved', $editor->get('globalSaveScopes')[$key]['state']);
        }
        $this->finish($form, $editor, $endpoint, $id);
        $form->assertRedirect()->assertSet('savingAll', false);
        self::assertSame('Endpoint changes saved.', session('status'));
        self::assertSame(5, $endpoint->fresh()->priority);
        self::assertSame($collection->id, $endpoint->fresh()->collection_id);
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        self::assertSame('after-a', $a->fresh()->body);
        self::assertSame('after-b', $b->fresh()->body);
        self::assertSame('https://receiver.test/delivery', $a->fresh()->callback_url);
    }

    public function test_untouched_add_form_and_selection_only_and_callback_only(): void
    {
        [$endpoint, $a, , $form, $editor] = $this->fixture();
        $id = $this->childSave($form, $editor, $endpoint);
        self::assertSame(2, $endpoint->responses()->count());
        self::assertSame(0, Revision::count());
        self::assertSame('No changes', $editor->get('globalSaveScopes')['selection']['state']);
        $this->finish($form, $editor, $endpoint, $id);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint->fresh()]);
        $editor->call('setSelectionMode', 'sequence');
        $id = $this->childSave($form, $editor, $endpoint);
        self::assertSame('Saved', $editor->get('globalSaveScopes')['selection']['state']);
        self::assertSame(2, $endpoint->responses()->count());
        $this->finish($form, $editor, $endpoint, $id);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint->fresh()]);
        $editor->call('edit', $a->id)->set('callbackUrl', 'https://receiver.test/callback-only');
        $id = $this->childSave($form, $editor, $endpoint);
        self::assertSame('No changes', $editor->get('globalSaveScopes')['response:'.$a->id]['state']);
        self::assertSame('Saved', $editor->get('globalSaveScopes')['callback:'.$a->id]['state']);
        self::assertSame('before-a', $a->fresh()->body);
        $this->finish($form, $editor, $endpoint, $id);
    }

    public static function invalidDrafts(): array
    {
        return [
            'early weight' => [false, 'weight', 0, 'weight'],
            'late weight' => [true, 'weight', 0, 'weight'],
            'headers' => [true, 'headersJson', '[]', 'headersJson'],
            'template' => [true, 'template', '{invalid', 'template'],
            'callback' => [true, 'callbackEnabled', true, 'callbackUrl'],
            'rules and fallback' => [true, 'selectionMode', 'rule', 'is_default'],
        ];
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_draft_rolls_back_all_rows_and_revisions(bool $late, string $field, mixed $value, string $error): void
    {
        [$endpoint, $a, $b, $form, $editor] = $this->fixture();
        $form->set('priority', 5);
        if ($late) {
            $editor->call('edit', $a->id)->set('body', 'after-a')->call('edit', $b->id)->set('body', 'after-b');
        } else {
            $editor->call('edit', $b->id)->set('body', 'after-b')->call('edit', $a->id)->set('body', 'after-a');
        }
        if ($field === 'template') {
            $editor->call('setBodyMode', 'template')->set('editorView', 'json');
        }
        $editor->set($field, $value);
        $id = $this->childSave($form, $editor, $endpoint);
        $editor->assertHasErrors($error)->assertNotDispatched('toast')->assertNotDispatched('response-drafts-saved');
        $form->dispatch('response-drafts-save-failed', endpointId: $endpoint->id, attemptId: $id, scopes: $editor->get('globalSaveScopes'))
            ->assertSet('savingAll', false)->assertSee(EndpointForm::RESPONSES_FAILED);
        self::assertSame('before-a', $a->fresh()->body);
        self::assertSame('before-b', $b->fresh()->body);
        self::assertSame(0, Revision::count());
        self::assertSame(0, $endpoint->fresh()->priority);
        foreach ($editor->get('globalSaveScopes') as $scope) {
            self::assertNotSame('Saved', $scope['state']);
        }
        self::assertTrue($editor->instance()->responseHasDraft($a->id));
        self::assertTrue($editor->instance()->responseHasDraft($b->id));
    }

    public function test_builder_validation_restores_all_cached_baselines(): void
    {
        [$endpoint, $a, $b, $form, $editor] = $this->fixture();
        $schema = app(TemplateSchemaConverter::class);
        $editor->call('edit', $a->id)->set('body', 'after-a')->call('edit', $b->id)->call('setBodyMode', 'template')
            ->set('builderSchema', ['fields' => [$schema->emptyRow('id'), $schema->emptyRow('id')]]);
        $baseline = $editor->get('formBaseline');
        $this->childSave($form, $editor, $endpoint);
        $editor->assertHasErrors('builderSchema.fields.1.key')->assertNotDispatched('toast');
        self::assertSame($baseline, $editor->get('formBaseline'));
        self::assertSame('before-a', $a->fresh()->body);
        self::assertSame(0, Revision::count());
    }

    public static function invalidEndpoints(): array
    {
        return [['rawCurl', 'not curl', 'rawCurl', 'request'], ['priority', 1001, 'priority', 'settings'], ['excludedHeaderNames', ['bad header'], 'excludedHeaderNames', 'matching']];
    }

    #[DataProvider('invalidEndpoints')]
    public function test_preflight_uses_existing_error_destinations(string $field, mixed $value, string $error, string $tab): void
    {
        [, , , $form] = $this->fixture();
        $form->set($field, $value)->call('saveAll')->assertHasErrors($error)->assertSet('activeTab', $tab)
            ->assertSet('savingAll', false)->assertNotDispatched('save-response-drafts')->assertSee(EndpointForm::PREFLIGHT_FAILED);
        self::assertSame(0, Revision::count());
    }

    public function test_response_database_exception_rolls_back_and_clears_parent_with_no_sensitive_log_message(): void
    {
        [$endpoint, $a, $b, $form, $editor] = $this->fixture();
        Log::spy();
        Event::listen('eloquent.updating: '.MockResponse::class, function ($response) use ($b) {
            if ($response->id === $b->id) {
                throw new RuntimeException('CANARY-SIGNING-SECRET');
            }
        });
        $editor->call('edit', $a->id)->set('body', 'after-a')->call('edit', $b->id)->set('body', 'after-b');
        $id = $this->childSave($form, $editor, $endpoint);
        $editor->assertNotDispatched('toast')->assertNotDispatched('response-drafts-saved');
        $form->dispatch('response-drafts-save-failed', endpointId: $endpoint->id, attemptId: $id, scopes: $editor->get('globalSaveScopes'), unexpected: true)
            ->assertSet('savingAll', false)->assertSee(EndpointForm::RESPONSES_ERROR);
        self::assertSame('before-a', $a->fresh()->body);
        self::assertSame('before-b', $b->fresh()->body);
        self::assertSame(0, Revision::count());
        self::assertTrue($editor->instance()->responseHasDraft($a->id));
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['exception_class'] === RuntimeException::class && ! str_contains(json_encode($context), 'CANARY-SIGNING-SECRET'));
        // A second attempt works: savingDrafts was cleared in finally.
        Event::forget('eloquent.updating: '.MockResponse::class);
        $editor->call('savePendingDrafts', $endpoint->id)->assertDispatched('response-drafts-saved');
    }

    public function test_endpoint_database_exception_retains_response_commit_and_newer_parent_input(): void
    {
        [$endpoint, $a, , $form, $editor] = $this->fixture();
        Log::spy();
        $form->set('priority', 5);
        $editor->call('edit', $a->id)->set('body', 'after-a');
        $id = $this->childSave($form, $editor, $endpoint);
        Event::listen('eloquent.updating: '.MockEndpoint::class, fn () => throw new RuntimeException('CANARY-SIGNING-SECRET'));
        $this->finish($form, $editor, $endpoint, $id);
        $form->assertSet('savingAll', false)->assertSee(EndpointForm::ENDPOINT_ERROR)->assertNoRedirect();
        self::assertSame('after-a', $a->fresh()->body);
        self::assertSame(0, $endpoint->fresh()->priority);
        self::assertNull(session('status'));
        Event::forget('eloquent.updating: '.MockEndpoint::class);
        $form->call('saveAll')->assertSet('savingAll', true);
        $next = $form->get('saveAttempt')['id'];
        $form->set('priority', 7);
        $form->dispatch('response-drafts-saved', endpointId: $endpoint->id, attemptId: $next, scopes: [])
            ->assertSet('priority', 7)->assertSet('savingAll', false)->assertNoRedirect();
        self::assertSame(5, $endpoint->fresh()->priority);
        self::assertSame(5, $form->get('requestBaseline')['priority']);
    }

    public function test_repeat_wrong_endpoint_and_stale_attempt_are_ignored(): void
    {
        [$endpoint, , , $form, $editor] = $this->fixture();
        $form->call('saveAll');
        $id = $form->get('saveAttempt')['id'];
        $form->call('saveAll')->assertSet('saveAttempt.id', $id)->assertNotDispatched('save-response-drafts');
        $form->dispatch('response-drafts-saved', endpointId: $endpoint->id + 1)->assertSet('savingAll', true);
        $form->dispatch('response-drafts-save-failed', endpointId: $endpoint->id, attemptId: 'stale')->assertSet('savingAll', true);
        $editor->call('savePendingDrafts', $endpoint->id + 1)->assertNotDispatched('response-drafts-saved');
    }

    public function test_blank_signing_secret_is_retained_and_status_contains_no_secret_and_invalidates_after_edit(): void
    {
        [$endpoint, $a, , $form, $editor] = $this->fixture();
        $a->update(['callback_signing_secret' => 'CANARY-SIGNING-SECRET', 'callback_signing_enabled' => true]);
        $ciphertext = DB::table('mock_responses')->where('id', $a->id)->value('callback_signing_secret');
        $editor->call('edit', $a->id)->assertSet('callbackSigningSecret', '')->set('body', 'after-a');
        $this->childSave($form, $editor, $endpoint);
        self::assertSame($ciphertext, DB::table('mock_responses')->where('id', $a->id)->value('callback_signing_secret'));
        self::assertStringNotContainsString('CANARY-SIGNING-SECRET', json_encode($editor->get('globalSaveScopes')));
        $editor->call('edit', $a->id)->set('body', 'a newer draft');
        self::assertArrayNotHasKey('response:'.$a->id, $editor->instance()->visibleSaveScopes());
    }

    public function test_new_response_result_uses_real_id_and_uncertain_acknowledgement_does_not_replay(): void
    {
        [$endpoint, , , $form, $editor] = $this->fixture();
        $editor->set('body', 'new response');
        $this->childSave($form, $editor, $endpoint);
        $created = $endpoint->responses()->where('body', 'new response')->sole();
        self::assertSame('Saved', $editor->get('globalSaveScopes')['response:'.$created->id]['state']);
        self::assertSame('Response #'.$created->id, $editor->get('globalSaveScopes')['response:'.$created->id]['label']);
        $revisions = Revision::count();
        $form->call('saveResultUnconfirmed')->assertSet('savingAll', false)->assertSee(EndpointForm::UNCONFIRMED);
        self::assertSame(3, $endpoint->responses()->count());
        self::assertSame($revisions, Revision::count());
        $editor->set('body', 'a different new draft')->call('inspectPendingDrafts', $endpoint->id, $form->get('saveAttempt')['id'], true);
        self::assertSame('Needs attention', $editor->get('globalSaveScopes')['response:new']['state']);
        self::assertSame(3, $endpoint->responses()->count());
    }

    public function test_endpoint_rejection_after_an_empty_response_transaction_does_not_claim_saved_changes(): void
    {
        [$endpoint, , , $form, $editor] = $this->fixture();
        $curl = "curl 'http://example.test/empty-response-transaction-race'";
        $form->set('rawCurl', $curl)->call('saveAll');
        $attempt = $form->get('saveAttempt')['id'];
        app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: $curl));
        $editor->call('savePendingDrafts', $endpoint->id, $attempt);
        $this->finish($form, $editor, $endpoint, $attempt);
        $form->assertSee(EndpointForm::PREFLIGHT_FAILED)->assertDontSee(EndpointForm::ENDPOINT_FAILED)->assertHasErrors('rawCurl');
        self::assertSame(0, Revision::count());
    }

    public function test_selection_commit_is_reported_saved_even_when_request_settings_are_rejected(): void
    {
        [$endpoint, , , $form, $editor] = $this->fixture();
        $before = $endpoint->only(['raw_curl', 'curl_hash', 'priority']);
        $curl = "curl 'http://example.test/selection-committed-before-race'";
        $form->set('rawCurl', $curl)->call('saveAll');
        $attempt = $form->get('saveAttempt')['id'];
        app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: $curl));
        $editor->call('setSelectionMode', 'sequence')->call('savePendingDrafts', $endpoint->id, $attempt);
        $this->finish($form, $editor, $endpoint, $attempt);
        $form->assertSee(EndpointForm::ENDPOINT_FAILED)->assertHasErrors('rawCurl')->assertSet('saveScopes.selection.state', 'Saved');
        self::assertSame($before, $endpoint->fresh()->only(array_keys($before)));
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        self::assertSame(1, Revision::where('entity_type', 'endpoint')->where('entity_id', $endpoint->id)->count());
    }

    public function test_standalone_response_failure_keeps_its_original_copy_after_a_global_attempt(): void
    {
        [$endpoint, $a, , $form, $editor] = $this->fixture();
        $editor->call('edit', $a->id)->set('weight', 0);
        $this->childSave($form, $editor, $endpoint);
        $editor->assertSet('globalSaveFailed', true)->assertHasErrors('weight');
        $editor->call('save')->assertSet('globalSaveFailed', false)->assertHasErrors('weight')
            ->assertSee('Response changes were not saved.')->assertNotDispatched('toast');
        self::assertSame('before-a', $a->fresh()->body);
        self::assertSame(0, Revision::count());
    }
}
