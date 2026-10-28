<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\Revision;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class EndpointSaveCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a guarded disposable PostgreSQL database; run this file explicitly.');
        }
        // Refuse migration against an unspecified database.
        self::assertSame('pgsql', DB::getDriverName());
        $expected = getenv('AUDIT_DB');
        self::assertNotEmpty($expected, 'Set AUDIT_DB to the disposable PostgreSQL database name.');
        self::assertSame($expected, DB::selectOne('select current_database() as name')->name);
    }

    /** Step 2 deliberately replaces the original partial-save characterization. */
    public function test_duplicate_before_preflight_preserves_every_draft_and_writes_nothing(): void
    {
        $mutations = app(EndpointMutationService::class);
        $endpoint = $mutations->save(new EndpointInput(rawCurl: "curl 'http://example.test/q01/original'"));
        $response = $endpoint->responses()->create(['body' => 'before']);
        $before = $endpoint->fresh()->getAttributes();
        $endpointRevisions = Revision::query()->where('entity_type', 'endpoint')->where('entity_id', $endpoint->id)->count();
        $responseRevisions = Revision::query()->where('entity_type', 'response')->where('entity_id', $response->id)->count();
        $newCurl = "curl 'http://example.test/q01/conflict'";

        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('edit', $response->id)->set('body', 'after')->set('callbackUrl', 'https://receiver.test/draft')
            ->set('callbackSigningSecret', 'CANARY-UNSAVED-SIGNING-SECRET')->call('setSelectionMode', 'sequence');
        $form->set('rawCurl', $newCurl)->assertHasNoErrors();

        // Another editor creates B after A's draft has been prepared.
        $other = $mutations->save(new EndpointInput(rawCurl: $newCurl));
        $otherBefore = $other->fresh()->getAttributes();
        $otherRevisions = Revision::query()->where('entity_type', 'endpoint')->where('entity_id', $other->id)->count();

        $form->call('saveAll')->assertSet('savingAll', false)->assertNoRedirect()
            ->assertNotDispatched('save-response-drafts')->assertHasErrors('rawCurl')
            ->assertSet('activeTab', 'request')->assertSee(EndpointForm::PREFLIGHT_FAILED)
            ->assertSee('This signature is already used by '.$other->displayName().'. Open that endpoint or change the matching request.');
        $editor->assertSet('body', 'after');
        $editor->call('inspectPendingDrafts', $endpoint->id, $form->get('saveAttempt')['id'])
            ->assertSet('selectionMode', 'sequence')->assertSet('callbackSigningSecret', 'CANARY-UNSAVED-SIGNING-SECRET');
        foreach (['selection', 'response:'.$response->id, 'callback:'.$response->id] as $key) {
            self::assertSame('Not saved', $editor->get('globalSaveScopes')[$key]['state']);
        }
        self::assertStringNotContainsString('CANARY-UNSAVED-SIGNING-SECRET', json_encode($editor->get('globalSaveScopes')));
        self::assertSame('before', $response->fresh()->body);
        self::assertSame($responseRevisions, Revision::query()->where('entity_type', 'response')->where('entity_id', $response->id)->count());
        self::assertSame($before, $endpoint->fresh()->getAttributes());
        self::assertSame($before['raw_curl'], $endpoint->fresh()->raw_curl);
        self::assertSame($before['curl_hash'], $endpoint->fresh()->curl_hash);
        self::assertSame($endpointRevisions, Revision::query()->where('entity_type', 'endpoint')->where('entity_id', $endpoint->id)->count());
        self::assertSame($otherBefore, $other->fresh()->getAttributes());
        self::assertSame($otherRevisions, Revision::query()->where('entity_type', 'endpoint')->where('entity_id', $other->id)->count());

        // This is the server-provided dirty contract consumed by editor-drafts.js,
        // rather than an invented PHP dirty flag or a simulated JS result.
        self::assertSame($newCurl, $form->get('rawCurl'));
        self::assertSame($before['raw_curl'], $form->get('requestBaseline')['rawCurl']);
        self::assertNotSame($form->get('requestBaseline'), $form->instance()->requestDraftValues());
        $form->assertSee('data-draft-baseline=', false)->assertSee('data-draft-current=', false);
        self::assertTrue($editor->instance()->responseHasDraft($response->id));
    }

    public function test_duplicate_after_preflight_keeps_committed_response_and_reports_partial_save(): void
    {
        $mutations = app(EndpointMutationService::class);
        $endpoint = $mutations->save(new EndpointInput(rawCurl: "curl 'http://example.test/q01/original'"));
        $response = $endpoint->responses()->create(['body' => 'before']);
        $before = $endpoint->fresh()->getAttributes();
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $editor = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $editor->call('edit', $response->id)->set('body', 'after');
        $curl = "curl 'http://example.test/q01/conflict'";
        $form->set('rawCurl', $curl)->call('saveAll')->assertSet('savingAll', true);
        $attempt = $form->get('saveAttempt')['id'];
        // The competing mutation happens AFTER preflight, before E.
        $other = $mutations->save(new EndpointInput(rawCurl: $curl));
        $otherBefore = $other->fresh()->getAttributes();
        $editor->dispatch('save-response-drafts', endpointId: $endpoint->id, attemptId: $attempt)
            ->assertHasNoErrors()->assertNotDispatched('toast');
        $form->dispatch('response-drafts-saved', endpointId: $endpoint->id, attemptId: $attempt, scopes: $editor->get('globalSaveScopes'))
            ->assertHasErrors('rawCurl')->assertSet('savingAll', false)->assertSet('activeTab', 'request')
            ->assertSee(EndpointForm::ENDPOINT_FAILED)->assertNoRedirect();
        self::assertSame('after', $response->fresh()->body);
        $revision = Revision::query()->where('entity_type', 'response')->where('entity_id', $response->id)->sole();
        self::assertSame('before', $revision->snapshot['body']);
        self::assertSame('manual_edit', $revision->source);
        self::assertSame($before, $endpoint->fresh()->getAttributes());
        self::assertSame(0, Revision::where('entity_type', 'endpoint')->count());
        self::assertSame($otherBefore, $other->fresh()->getAttributes());
        self::assertSame('Needs attention', $form->get('saveScopes')['request']['state']);
        self::assertSame('Saved', $form->get('saveScopes')['response:'.$response->id]['state']);
        self::assertSame('No changes', $form->get('saveScopes')['selection']['state']);
        self::assertNull(session('status'));
        self::assertNotSame($form->get('requestBaseline'), $form->instance()->requestDraftValues());
        $editor->assertSet('pendingFormDrafts', [])->assertSee('data-server-dirty="false"', false);
    }
}
