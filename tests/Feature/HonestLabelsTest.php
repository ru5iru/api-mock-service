<?php

namespace Tests\Feature;

use App\Livewire\Admin\CallbackLog;
use App\Livewire\Admin\ConfigTransfer;
use App\Livewire\Admin\EnvironmentSwitcher;
use App\Livewire\Admin\LogViewer;
use App\Livewire\Admin\ResponseManager;
use App\Models\CallbackAttempt;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HonestLabelsFixture;
use Tests\TestCase;

final class HonestLabelsTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        return [['native'], ['postman']];
    }

    #[DataProvider('formats')]
    public function test_mixed_import_labels_describe_actual_restoration_and_retention(string $format): void
    {
        if ($format === 'native') {
            $fixture = HonestLabelsFixture::native();
            $document = $fixture['document'];
            $existing = MockEndpoint::query()->findOrFail($fixture['endpoint']);
            $removedId = $fixture['removed'];
            $createdName = 'Q02 retained new endpoint';
            $restoredBody = 'before import';
            $environmentId = $fixture['environment_id'];
        } else {
            $document = ['info' => ['name' => 'Q02 Postman', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
                'item' => [['name' => 'Q02 Postman existing', 'request' => ['method' => 'GET', 'url' => 'https://example.test/q02/postman'],
                    'response' => [['name' => 'Original', 'code' => 200, 'body' => 'postman before'], ['name' => 'Removed', 'code' => 201, 'body' => 'restore example']]]]];
            $service = app(MappedImportService::class);
            $plan = $service->preview(app(PostmanMapper::class), json_encode($document, JSON_THROW_ON_ERROR), ImportMode::CreateOnly);
            $service->apply($plan->token, $plan->digest);
            $existing = MockEndpoint::query()->sole();
            $removedId = $existing->responses()->orderBy('id')->get()->last()->id;
            $document['item'][0]['response'] = [['name' => 'Changed', 'code' => 200, 'body' => 'postman after']];
            $document['item'][] = ['name' => 'Q02 Postman retained', 'request' => ['method' => 'GET', 'url' => 'https://example.test/q02/new-postman'], 'response' => []];
            $createdName = 'Q02 Postman retained';
            $restoredBody = 'postman before';
            $environment = Environment::query()->where('is_default', true)->firstOrFail();
            $environmentId = $environment->id;
            $environment->variables()->create(['key' => 'Q02_SEPARATE', 'value' => 'separately created']);
            EndpointCallState::query()->create(['endpoint_id' => $existing->id, 'environment_id' => $environment->id, 'total_match_count' => 7, 'sequence_position' => 2]);
        }
        $beforeName = $existing->name;
        $beforeResponseCount = $existing->responses()->count();
        $component = Livewire::test(ConfigTransfer::class)->set('format', $format)->set('mode', 'upsert')
            ->set('replaceResponses', true)->set('configFile', UploadedFile::fake()->createWithContent('mixed.json', json_encode($document, JSON_THROW_ON_ERROR)))
            ->call('preview')->assertHasNoErrors();
        if ($format === 'postman') {
            $component->assertSee('Exclude keeps the field out of matching.')
                ->assertSee("Resolve substitutes the selected Environment's current value into the signature at preview time and does not follow later changes.", false);
        }
        $component->set('acknowledgeWarnings', true)->call('apply')->assertHasNoErrors();
        $summary = $component->get('summary');
        self::assertSame(1, $summary['endpoints_created']);
        self::assertSame(1, $summary['endpoints_updated']);
        $states = Revision::query()->where('import_batch_id', $summary['import_batch_id'])->where('source', 'import')->count();
        $component->assertSee('Restore 1 endpoint and '.$beforeResponseCount.' responses from '.$states.' recorded import '.($states === 1 ? 'snapshot' : 'snapshots'))
            ->assertSee('Retain '.$summary['endpoints_created'].' newly created endpoint: '.$createdName)
            ->assertSee($beforeName)->assertSee('including responses removed by authoritative replacement')
            ->assertSee('separately created Environment variables are retained')->assertSee('Runtime counters are untouched');
        preg_match('/data-confirm-title="Undo import"[^>]*data-confirm="([^"]+)"/', $component->html(), $match);
        $confirmation = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString($beforeName, $confirmation);
        self::assertStringContainsString($createdName, $confirmation);
        self::assertStringContainsString('Runtime counters are untouched.', $confirmation);
        self::assertNull(MockResponse::query()->find($removedId));
        $createdId = MockEndpoint::query()->where('name', $createdName)->sole()->id;
        $component->call('undoImport')->assertHasNoErrors()->assertSee('Import undone');
        self::assertSame($beforeName, $existing->refresh()->name);
        self::assertTrue($existing->responses()->where('body', $restoredBody)->exists());
        self::assertNotNull(MockResponse::query()->find($removedId));
        self::assertNotNull(MockEndpoint::query()->find($createdId));
        self::assertSame(7, EndpointCallState::query()->where('endpoint_id', $existing->id)->sole()->total_match_count);
        self::assertSame(2, EndpointCallState::query()->where('endpoint_id', $existing->id)->sole()->sequence_position);
        self::assertSame('separately created', Environment::query()->findOrFail($environmentId)->variables()->where('key', 'Q02_SEPARATE')->sole()->value);
    }

    public function test_log_groups_distinguish_recorded_event_and_current_names(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Current endpoint name']);
        $event = ['request_id' => 'q02-recorded', 'method' => 'GET', 'url' => url('/q02/recorded'), 'endpoint_id' => $endpoint->id,
            'response_id' => 567, 'match_tier' => 'hash', 'status_code' => 201, 'environment' => 'Recorded environment'];
        $file = storage_path('logs/mock-requests-q02-labels.log');
        try {
            file_put_contents($file, json_encode($event, JSON_THROW_ON_ERROR)."\n");
            Livewire::test(LogViewer::class, ['full' => true])->set('timeRange', 'all')->set('search', 'q02-recorded')
                ->call('toggleExpanded', hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)))
                ->assertSee('Recorded request event')->assertSee('Current configuration')->assertSee('Current endpoint name')
                ->assertSee('Recorded environment')->assertSee('#567')->assertSee('201')
                ->assertSee('Method, URL, match, status, response ID and Environment are recorded at request time.')
                ->assertSee('The reconstructed cURL contains method and URL only; add headers and body before using it.');
        } finally {
            unlink($file);
        }
    }

    public function test_environment_callback_and_rule_help_explain_existing_boundaries(): void
    {
        Livewire::test(EnvironmentSwitcher::class)
            ->assertSee('Selecting an Environment changes the active Environment for the whole instance and applies immediately to ordinary mock traffic.')
            ->assertSee('Test runs keep their bound Environment.');
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $response = $endpoint->responses()->create(['body' => 'rule', 'external_label' => 'Current label', 'callback_url' => 'https://current.example.test/hook']);
        $response->rules()->create(['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'test', 'priority' => 0]);
        $endpoint->responses()->create(['body' => 'fallback', 'is_default' => true]);
        Livewire::test(ResponseManager::class, ['endpoint' => $endpoint])->call('editCallback', $response->id, false)
            ->assertSee('A rule cannot make a different request match.')
            ->assertSee('exclude it in Matching first, then choose the response with a rule.')
            ->assertSee('using the invocation\'s bound Environment and its current values and the current saved callback settings', false)
            ->assertSee('Disabling a callback stops enqueues from future mock calls but does not cancel a delivery already queued.');
        CallbackAttempt::query()->create(['response_id' => $response->id, 'target_url' => 'https://recorded.example.test/hook',
            'resolved_headers' => [], 'resolved_body' => '{}', 'method' => 'POST', 'attempt_number' => 1, 'status' => 'success']);
        Livewire::test(CallbackLog::class)->assertSee('Recorded target')->assertSee('recorded.example.test')->assertDontSee('current.example.test')
            ->assertSee('Response / current label')->assertSee('Current label')->assertSee('The response label uses current configuration.');
    }
}
