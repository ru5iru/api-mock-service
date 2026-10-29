<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Environments\EnvironmentContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EndpointWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public static function tabs(): array
    {
        return [['request', 'request'], ['matching', 'matching'], ['response', 'response'], ['callback', 'callback'], ['settings', 'settings'], ['editor', 'request'], ['unknown', 'request']];
    }

    #[DataProvider('tabs')]
    public function test_deep_links_keep_legacy_sections_and_accept_new_workspace_names(string $tab, string $expected): void
    {
        Livewire::withQueryParams(['tab' => $tab])->test(EndpointForm::class)->assertSet('activeTab', $expected)
            ->assertSeeHtml('id="tab-editor"')->assertSeeHtml('id="tab-settings"')->assertSeeHtml('id="tab-callback"');
    }

    public function test_task_switching_never_mutates_configuration_signature_or_portable_export(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $endpoint->responses()->create(['body' => 'literal response']);
        $this->freezeTime();
        $exporter = app(ConfigExporter::class);
        $before = $exporter->export()->toJson();
        $hash = $endpoint->curl_hash;
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        foreach (['settings', 'editor', 'matching', 'response', 'callback'] as $tab) {
            $form->call('showEditorTab', $tab)->call('touchSection', $tab);
        }
        self::assertSame($hash, $endpoint->fresh()->curl_hash);
        self::assertSame($before, $exporter->export()->toJson());
        self::assertSame(0, Revision::query()->count());
    }

    public function test_request_and_response_forms_are_siblings_and_settings_keep_the_request_save_boundary(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $endpoint->responses()->create(['body' => 'literal']);
        $html = $this->get('/dashboard/endpoints/'.$endpoint->id.'/edit')->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//*[@id="endpoint-settings"]')->length);
        self::assertSame(0, $xpath->query('//*[@id="endpoint-settings"]//form')->length);
        self::assertSame(1, $xpath->query('//*[@id="endpoint-settings"]//*[@id="endpoint-priority"]')->length);
        self::assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " response-manager ")]')->length);
        self::assertSame(1, $xpath->query('//*[@id="response-to-edit"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="panel-settings"]//*[@id="endpoint-collection"]')->length);
    }

    public function test_new_selector_actions_preserve_other_response_and_new_drafts(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $a = $endpoint->responses()->create(['body' => 'saved A']);
        $b = $endpoint->responses()->create(['body' => 'saved B']);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->set('body', 'new draft')
            ->call('edit', $a->id)->set('body', 'draft A')
            ->call('edit', $b->id)->set('body', 'draft B')
            ->call('createNew')->assertSet('body', 'new draft')
            ->call('edit', $a->id)->assertSet('body', 'draft A')
            ->call('edit', $b->id)->assertSet('body', 'draft B');
        self::assertSame('saved A', $a->fresh()->body);
        self::assertSame('saved B', $b->fresh()->body);
        self::assertSame(2, $endpoint->responses()->count());
        self::assertSame(0, Revision::query()->count());
    }

    public function test_editor_copy_resolves_the_saved_request_at_action_time_without_embedding_environment_secrets(): void
    {
        config(['app.url' => 'http://mock.test:18473']);
        $environment = app(EnvironmentContext::class)->active();
        $variable = $environment->variables()->create(['key' => 'access_token', 'value' => 'first-secret', 'is_secret' => true]);
        $endpoint = MockEndpoint::factory()->create(['raw_curl' => "curl 'https://source.test/saved' -H 'Authorization: Bearer {{access_token}}'"]);
        $form = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $form->assertDontSee('first-secret')->assertSeeHtml('data-copy-endpoint="'.$endpoint->id.'"');
        $variable->update(['value' => 'second-secret']);
        $form->set('rawCurl', "curl 'https://source.test/unsaved'");
        $copied = $form->instance()->copyMockCurl($endpoint->id);
        self::assertStringContainsString('http://mock.test:18473/saved', $copied);
        self::assertStringContainsString('Bearer second-secret', $copied);
        self::assertStringNotContainsString('/unsaved', $copied);
        $form->assertDontSee('second-secret');
    }
}
