<?php

namespace Tests\Feature;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ConfigValidator;
use App\Services\Config\ImportMode;
use App\Services\Revisions\RevisionManager;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WaveOnePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_selection_configuration_round_trips_without_runtime_state_and_upsert_preserves_state(): void
    {
        $environment = Environment::query()->where('is_default', true)->firstOrFail();
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $conditional = MockResponse::factory()->for($endpoint, 'endpoint')->create(['weight' => 9]);
        $conditional->rules()->createMany([
            ['field_type' => 'header', 'field_name' => 'X-State', 'operator' => 'equals', 'value' => 'ready', 'priority' => 2],
            ['field_type' => 'body_json_path', 'field_name' => 'user.id', 'operator' => 'exists', 'value' => null, 'priority' => 3],
        ]);
        $fallback = MockResponse::factory()->for($endpoint, 'endpoint')->create(['is_default' => true]);
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => $environment->id, 'sequence_position' => 7, 'total_match_count' => 13, 'last_matched_at' => now()]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.5', $document['format_version']);
        foreach (['endpoint_call_state', 'sequence_position', 'total_match_count', 'last_matched_at', 'response_id', 'environment_id'] as $excluded) {
            self::assertStringNotContainsString('"'.$excluded.'"', $json);
        }
        $plan = app(ConfigImporter::class)->preview($json, ImportMode::Upsert);
        self::assertSame(0, $plan->counts['revision_snapshots']);
        $this->import($json, ImportMode::Upsert);
        self::assertSame(13, $state->fresh()->total_match_count);
        self::assertSame(7, $state->fresh()->sequence_position);
        $conditionalUuid = $conditional->uuid;
        $fallbackUuid = $fallback->uuid;
        $endpoint->delete();
        $this->import($json);
        $imported = MockEndpoint::query()->sole();
        self::assertSame('rule', $imported->selection_mode);
        self::assertSame(0, EndpointCallState::query()->count());
        self::assertTrue($imported->responses()->where('uuid', $fallbackUuid)->sole()->is_default);
        self::assertSame(['header', 'body_json_path'], $imported->responses()->where('uuid', $conditionalUuid)->sole()->rules()->orderBy('priority')->pluck('field_type')->all());
    }

    public function test_sequence_fields_round_trip_and_imported_clone_has_no_state(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 1, 'weight' => 17]);
        MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();
        $this->import($json, ImportMode::Clone);
        $imported = MockEndpoint::query()->sole();
        self::assertSame('sequence', $imported->selection_mode);
        self::assertSame('loop', $imported->sequence_on_exhaust);
        self::assertNotSame($endpoint->uuid, $imported->uuid);
        self::assertSame([0, 1], $imported->responses()->reorder('sequence_order')->pluck('sequence_order')->all());
        self::assertSame(17, $imported->responses()->where('sequence_order', 1)->sole()->weight);
        self::assertSame(0, EndpointCallState::query()->count());
    }

    public function test_older_versions_cannot_activate_selection_fields_and_default_to_weighted(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0, 'is_default' => true]);
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $endpoint->delete();
        foreach ([1, '1.0', '1.1', '1.2'] as $version) {
            $document['format_version'] = $version;
            $this->import(json_encode($document, JSON_THROW_ON_ERROR));
            $imported = MockEndpoint::query()->sole();
            self::assertSame('weighted', $imported->selection_mode);
            self::assertNull($imported->sequence_on_exhaust);
            self::assertNull($imported->responses()->sole()->sequence_order);
            self::assertFalse($imported->responses()->sole()->is_default);
            self::assertSame(0, $imported->responses()->sole()->rules()->count());
            $imported->delete();
        }
    }

    public function test_request_context_template_round_trip_preserves_text_and_actual_rendering(): void
    {
        $environment = Environment::query()->where('is_default', true)->firstOrFail();
        $environment->variables()->create(['key' => 'GREETING', 'value' => 'Hello', 'is_secret' => false]);
        $endpoint = MockEndpoint::factory()->create();
        $template = '{"method":"$request.method","id":"$request.id","user":"$request.json.user.id","message":"{{env.GREETING}} {{$request.method}}"}';
        MockResponse::factory()->for($endpoint, 'endpoint')->create(['body_mode' => 'template', 'template' => $template, 'editor_view' => 'json']);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();
        $this->import($json);
        $response = MockEndpoint::query()->sole()->responses()->sole();
        self::assertSame($template, $response->template);
        $contexts = app(TemplateContext::class);
        $request = Request::create('/orders', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"user":{"id":42}}');
        $context = $contexts->build($contexts->capture($request, 'round-trip-request'), $environment->fresh());
        $rendered = app(ResponseTemplateEngine::class)->renderResponse($response, context: $context);
        self::assertSame(['method' => 'POST', 'id' => 'round-trip-request', 'user' => 42, 'message' => 'Hello POST'], json_decode($rendered->json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_invalid_rule_default_and_sequence_orders_are_rejected_during_preview(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $document['endpoints'][0]['selection_mode'] = 'rule';
        $invalid = app(ConfigValidator::class)->validate(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($invalid->valid());
        self::assertStringContainsString('exactly one default', implode(' ', $invalid->errors));
        $document['endpoints'][0]['selection_mode'] = 'sequence';
        $document['endpoints'][0]['sequence_on_exhaust'] = 'repeat_last';
        $document['endpoints'][0]['responses'][0]['sequence_order'] = 3;
        $invalid = app(ConfigValidator::class)->validate(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($invalid->valid());
        self::assertStringContainsString('each position from 0', implode(' ', $invalid->errors));
    }

    public function test_revisions_restore_selection_fields_rules_and_legacy_defaults_without_runtime_state(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        $response = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0]);
        $manager = app(RevisionManager::class);
        $before = $manager->snapshot($endpoint);
        $endpoint->update(['sequence_on_exhaust' => 'repeat_last']);
        $revision = $manager->recordIfChanged($endpoint, $before);
        $environment = Environment::query()->where('is_default', true)->firstOrFail();
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => $environment->id, 'sequence_position' => 5, 'total_match_count' => 8]);
        $manager->restore($revision);
        self::assertSame('loop', $endpoint->fresh()->sequence_on_exhaust);
        self::assertSame(5, $state->fresh()->sequence_position);
        self::assertArrayNotHasKey('endpoint_call_state', $before);
        $legacy = $before;
        unset($legacy['selection_mode'], $legacy['sequence_on_exhaust']);
        $manager->restore($manager->record($endpoint, $legacy));
        self::assertSame('weighted', $endpoint->fresh()->selection_mode);
        self::assertNull($endpoint->fresh()->sequence_on_exhaust);
        $response->update(['is_default' => true]);
        $response->rules()->create(['field_type' => 'query', 'field_name' => 'mode', 'operator' => 'equals', 'value' => 'test', 'priority' => 0]);
        $beforeResponse = $manager->snapshot($response->fresh());
        $response->rules()->delete();
        $response->update(['is_default' => false, 'sequence_order' => null]);
        $manager->restore($manager->record($response, $beforeResponse));
        self::assertTrue($response->fresh()->is_default);
        self::assertSame(0, $response->fresh()->sequence_order);
        self::assertSame('test', $response->rules()->sole()->value);
        unset($beforeResponse['sequence_order'], $beforeResponse['is_default'], $beforeResponse['response_rules']);
        $manager->restore($manager->record($response, $beforeResponse));
        self::assertFalse($response->fresh()->is_default);
        self::assertNull($response->fresh()->sequence_order);
        self::assertSame(0, $response->rules()->count());
        self::assertSame(8, $state->fresh()->total_match_count);
    }

    public function test_restore_cannot_remove_the_only_rule_fallback_and_restoring_another_fallback_replaces_it(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $fallback = MockResponse::factory()->for($endpoint, 'endpoint')->create(['is_default' => true]);
        $other = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $manager = app(RevisionManager::class);
        $snapshot = $manager->snapshot($fallback);
        $snapshot['is_default'] = false;
        try {
            $manager->restore($manager->record($fallback, $snapshot));
            self::fail('Removing the only fallback should be rejected.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('exactly one Default / fallback', $exception->getMessage());
        }
        self::assertTrue($fallback->fresh()->is_default);
        $snapshot = $manager->snapshot($other);
        $snapshot['is_default'] = true;
        $manager->restore($manager->record($other, $snapshot));
        self::assertTrue($other->fresh()->is_default);
        self::assertFalse($fallback->fresh()->is_default);
        self::assertSame(1, $endpoint->responses()->where('is_default', true)->count());
        self::assertSame('rollback', $fallback->revisions()->latest('id')->firstOrFail()->source);
    }

    public function test_import_undo_restores_rule_fallback_changes_atomically(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $first = MockResponse::factory()->for($endpoint, 'endpoint')->create(['is_default' => true]);
        $second = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($document['endpoints'][0]['responses'] as &$response) {
            $response['is_default'] = $response['uuid'] === $second->uuid;
        }
        unset($response);
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview(json_encode($document, JSON_THROW_ON_ERROR), ImportMode::Upsert);
        self::assertTrue($plan->canApply());
        $summary = $importer->apply($plan->token, $plan->digest, true);
        self::assertSame(2, $summary->revisionSnapshots);
        self::assertTrue($second->fresh()->is_default);
        app(RevisionManager::class)->undoImport($summary->importBatchId);
        self::assertTrue($first->fresh()->is_default);
        self::assertFalse($second->fresh()->is_default);
        self::assertSame(1, $endpoint->responses()->where('is_default', true)->count());
    }

    public function test_merge_preview_rejects_two_fallbacks_and_malformed_rules_fail_validation(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'rule']);
        $fallback = MockResponse::factory()->for($endpoint, 'endpoint')->create(['is_default' => true]);
        $other = MockResponse::factory()->for($endpoint, 'endpoint')->create();
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $source = collect($document['endpoints'][0]['responses'])->firstWhere('uuid', $other->uuid);
        $source['is_default'] = true;
        $document['endpoints'][0]['responses'] = [$source];
        $plan = app(ConfigImporter::class)->preview(json_encode($document, JSON_THROW_ON_ERROR), ImportMode::Upsert);
        self::assertFalse($plan->canApply());
        self::assertStringContainsString('Merged response selection is invalid', implode(' ', $plan->errors));
        $document['endpoints'][0]['responses'][0]['response_rules'] = [
            ['field_type' => 'header', 'field_name' => 'X-State', 'operator' => 'regex', 'value' => '[invalid', 'priority' => 0],
        ];
        $result = app(ConfigValidator::class)->validate(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($result->valid());
        self::assertStringContainsString('regular expression', implode(' ', $result->errors));
        self::assertTrue($fallback->fresh()->is_default);
    }

    public function test_response_revision_restore_moves_siblings_and_preserves_runtime_counters(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        $first = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0]);
        $second = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 1]);
        $manager = app(RevisionManager::class);
        $before = $manager->snapshot($first);
        $first->update(['sequence_order' => 1]);
        $second->update(['sequence_order' => 0]);
        $revision = $manager->recordIfChanged($first, $before);
        $environment = Environment::query()->where('is_default', true)->firstOrFail();
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => $environment->id, 'sequence_position' => 7, 'total_match_count' => 11]);
        $manager->restore($revision);
        self::assertSame(0, $first->fresh()->sequence_order);
        self::assertSame(1, $second->fresh()->sequence_order);
        self::assertSame(1, $second->revisions()->where('source', 'rollback')->count());
        self::assertSame(7, $state->fresh()->sequence_position);
        self::assertSame(11, $state->fresh()->total_match_count);
    }

    public function test_endpoint_sequence_revision_restore_initializes_missing_orders_deterministically(): void
    {
        $endpoint = MockEndpoint::factory()->create(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'repeat_last']);
        $first = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 0]);
        $second = MockResponse::factory()->for($endpoint, 'endpoint')->create(['sequence_order' => 1]);
        $manager = app(RevisionManager::class);
        $before = $manager->snapshot($endpoint);
        $endpoint->update(['selection_mode' => 'weighted', 'sequence_on_exhaust' => null]);
        $endpoint->responses()->update(['sequence_order' => null]);
        $manager->restore($manager->recordIfChanged($endpoint, $before));
        self::assertSame('sequence', $endpoint->fresh()->selection_mode);
        self::assertSame([0, 1], $endpoint->responses()->reorder('sequence_order')->pluck('sequence_order')->all());
        self::assertSame(1, $first->revisions()->where('source', 'rollback')->count());
        self::assertSame(1, $second->revisions()->where('source', 'rollback')->count());
        self::assertSame(0, EndpointCallState::query()->count());
    }

    private function import(string $json, ImportMode $mode = ImportMode::CreateOnly): void
    {
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, $mode);
        self::assertTrue($plan->canApply(), implode(' ', $plan->errors));
        $importer->apply($plan->token, $plan->digest, true);
    }
}
