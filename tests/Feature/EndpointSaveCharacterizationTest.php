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
        // Refuse SQLite and refuse migration against an unspecified database.
        self::assertSame('pgsql', DB::getDriverName());
        $expected = getenv('AUDIT_DB');
        self::assertNotEmpty($expected, 'Set AUDIT_DB to the disposable PostgreSQL database name.');
        self::assertSame($expected, DB::selectOne('select current_database() as name')->name);
    }

    /**
     * CHARACTERIZATION: Step 2 is intended to change this partial-save result.
     * Testable components do not route events between independent instances.
     * Relay the asserted real events through their listeners, as in
     * EndpointSaveDraftsTest; the report supplements this with a browser run.
     */
    public function test_characterization_global_save_commits_response_before_stale_duplicate_rejects_endpoint(): void
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
        $editor->call('edit', $response->id)->set('body', 'after');
        $form->set('rawCurl', $newCurl)->assertHasNoErrors();

        // Another editor creates B after A's draft has been prepared.
        $other = $mutations->save(new EndpointInput(rawCurl: $newCurl));
        $otherBefore = $other->fresh()->getAttributes();
        $otherRevisions = Revision::query()->where('entity_type', 'endpoint')->where('entity_id', $other->id)->count();

        // Invoke global Save once; relay the two component-boundary events.
        $form->call('saveAll')->assertSet('savingAll', true)->assertNoRedirect()
            ->assertDispatched('save-response-drafts', endpointId: $endpoint->id);
        $editor->dispatch('save-response-drafts', endpointId: $endpoint->id)->assertHasNoErrors()
            ->assertDispatched('response-drafts-saved', endpointId: $endpoint->id);
        $form->dispatch('response-drafts-saved', endpointId: $endpoint->id)
            ->assertHasErrors('rawCurl')->assertSet('savingAll', false)
            ->assertSet('activeTab', 'request')->assertNoRedirect()
            ->assertSee('This signature is already used by '.$other->displayName().'. Open that endpoint or change the matching request.');

        self::assertSame('after', $response->fresh()->body);
        $revisions = Revision::query()->where('entity_type', 'response')->where('entity_id', $response->id);
        self::assertSame($responseRevisions + 1, $revisions->count());
        self::assertSame('before', $revisions->latest('id')->firstOrFail()->snapshot['body']);
        self::assertSame('manual_edit', $revisions->latest('id')->firstOrFail()->source);
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
        $editor->assertSet('pendingFormDrafts', [])->assertSee('data-server-dirty="false"', false);
    }
}
