<?php

namespace Tests\Feature;

use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\EndpointIndex;
use App\Livewire\Admin\LogViewer;
use App\Livewire\Admin\ResponseManager;
use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FirstRunClarityTest extends TestCase
{
    use RefreshDatabase;

    public static function sources(): array
    {
        return [['created'], ['imported']];
    }

    private function emptyEndpoint(string $source): MockEndpoint
    {
        if ($source === 'created') {
            Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'https://example.test/q03/empty'")->call('save');
        } else {
            $imports = app(MappedImportService::class);
            $json = json_encode(['info' => ['name' => 'Empty import', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
                'item' => [['name' => 'Empty import', 'request' => ['method' => 'GET', 'url' => 'https://example.test/q03/empty'], 'response' => []]],
            ], JSON_THROW_ON_ERROR);
            $plan = $imports->preview(app(PostmanMapper::class), $json, ImportMode::CreateOnly);
            self::assertSame([], $plan->errors);
            $imports->apply($plan->token, $plan->digest);
        }

        return MockEndpoint::query()->sole();
    }

    #[DataProvider('sources')]
    public function test_zero_response_cues_and_serving_contracts_for_created_and_imported_endpoints(string $source): void
    {
        $endpoint = $this->emptyEndpoint($source);
        self::assertSame(0, $endpoint->responses()->count());
        Livewire::test(EndpointIndex::class)->assertSee('No response')->assertSeeHtml('?tab=response')->assertSee('Add response');
        $editor = Livewire::test(EndpointForm::class, ['endpoint' => $endpoint]);
        $editor->assertSeeHtml('aria-describedby="response-empty-note"')->assertSee('No response yet');
        $manager = Livewire::test(ResponseManager::class, ['endpoint' => $endpoint]);
        $manager->assertSee('No response yet. Add one so this endpoint can answer requests. Until then it returns a diagnostic 500.');
        $this->get('/q03/empty')->assertStatus(500)->assertExactJson(['error' => 'The matched mock has no configured responses', 'endpoint_id' => $endpoint->id]);
        $this->get('/q03/not-configured')->assertNotFound()->assertJsonPath('error', 'No mock configured for this request');
        $manager->set('body', '{"ok":true}')->call('save')->assertHasNoErrors()->assertDontSee('No response yet.');
        $editor->dispatch('response-selection-validity', endpointId: $endpoint->id, valid: true)
            ->assertDontSeeHtml('aria-describedby="response-empty-note"');
        Livewire::test(EndpointIndex::class)->assertDontSee('Add response');
        self::assertSame(1, $endpoint->responses()->count());
        $this->get('/q03/empty')->assertOk()->assertExactJson(['ok' => true]);
    }

    public static function candidates(): array
    {
        return [['GET', '/q03/cookies?different=1', true], ['POST', '/q03/cookies', false], ['GET', '/q03/other', false], [null, null, false]];
    }

    #[DataProvider('candidates')]
    public function test_cookie_hint_requires_the_nearest_candidate_to_share_method_and_path(?string $method, ?string $target, bool $visible): void
    {
        if ($method !== null) {
            MockEndpoint::factory()->create(['method' => $method, 'normalized_curl' => $method."\n".$target."\n\n"]);
        }
        $event = ['request_id' => 'q03-cookie-test', 'method' => 'GET', 'url' => url('/q03/cookies'), 'match_tier' => 'none', 'status_code' => 404];
        $file = storage_path('logs/mock-requests-q03-test.log');
        try {
            file_put_contents($file, json_encode($event, JSON_THROW_ON_ERROR)."\n");
            $component = Livewire::test(LogViewer::class, ['full' => true])->set('timeRange', 'all')->set('search', 'q03-cookie-test')
                ->call('toggleExpanded', hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)));
            if ($visible) {
                $component->assertSee('If this request came from a browser')->assertSee('Ignore cookies');
            } else {
                $component->assertDontSee('If this request came from a browser');
            }
            $component->assertSee('Request headers, body, and the computed hash are not present');
        } finally {
            unlink($file);
        }
    }
}
