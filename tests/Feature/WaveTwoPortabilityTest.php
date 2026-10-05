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
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WaveTwoPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): MockEndpoint
    {
        $raw = "curl 'https://example.test/users/{id}?noise=old&keep=1' -H 'X-Trace: old'";
        $variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, false, false, ['noise'], ['x-trace'], true);
        $endpoint = MockEndpoint::factory()->create([
            'raw_curl' => $raw, 'method' => 'GET', 'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
            'signature_version' => 6, 'exclude_cookies' => false, 'exclude_auth' => false, 'exclude_headers' => false,
            'excluded_query_params' => ['noise'], 'excluded_headers' => ['x-trace'], 'path_pattern_enabled' => true,
        ]);
        MockResponse::factory()->for($endpoint, 'endpoint')->create([
            'body_mode' => 'template', 'template' => '{"id":"$request.path.id"}', 'editor_view' => 'json',
            'fault_enabled' => true, 'fault_type' => 'delay', 'fault_delay_ms_min' => 1, 'fault_delay_ms_max' => 2, 'fault_probability' => 37,
        ]);

        return $endpoint;
    }

    private function import(string $json, ImportMode $mode = ImportMode::CreateOnly): void
    {
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, $mode);
        self::assertTrue($plan->canApply(), json_encode($plan->errors));
        $importer->apply($plan->token, $plan->digest, true);
    }

    public function test_format_14_round_trip_preserves_both_tracks_and_excludes_all_runtime_state(): void
    {
        $endpoint = $this->configured();
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => Environment::query()->sole()->id,
            'total_match_count' => 7, 'recent_call_digests' => [['matched_at' => now()->toIso8601String(), 'method' => 'GET']]]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $document = json_decode($json, true);
        self::assertSame('1.5', $document['format_version']);
        foreach (['recent_call_digests', 'total_match_count', 'api_tokens', 'token_hash', 'endpoint_call_state'] as $field) {
            self::assertStringNotContainsString('"'.$field.'"', $json);
        }
        $before = app(RevisionManager::class)->snapshot($endpoint->responses()->sole());
        $this->import($json, ImportMode::Upsert);
        self::assertSame(7, $state->fresh()->total_match_count);
        self::assertSame($before, app(RevisionManager::class)->snapshot($endpoint->responses()->sole()));
        $signature = [$endpoint->normalized_curl, $endpoint->curl_hash, $endpoint->signature_version];
        $endpoint->delete();
        $this->import($json);
        $imported = MockEndpoint::query()->sole();
        self::assertSame($signature, [$imported->normalized_curl, $imported->curl_hash, $imported->signature_version]);
        self::assertSame(['noise'], $imported->excluded_query_params);
        self::assertSame(['x-trace'], $imported->excluded_headers);
        self::assertTrue($imported->path_pattern_enabled);
        self::assertSame(0, EndpointCallState::query()->count());
        self::assertSame(37, $imported->responses()->sole()->fault_probability);
        self::assertSame('{"id":"$request.path.id"}', $imported->responses()->sole()->template);
        $imported->delete();
        $this->import($json, ImportMode::Clone);
        self::assertSame(0, EndpointCallState::query()->count());
    }

    public function test_pre_wave_two_formats_cannot_enable_new_features_even_with_injected_new_fields(): void
    {
        $endpoint = $this->configured();
        $document = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true);
        $endpoint->delete();
        foreach ([1, '1.0', '1.1', '1.2', '1.3'] as $version) {
            $document['format_version'] = $version;
            $this->import(json_encode($document));
            $imported = MockEndpoint::query()->sole();
            self::assertSame([], $imported->excluded_query_params);
            self::assertSame([], $imported->excluded_headers);
            self::assertFalse($imported->path_pattern_enabled);
            self::assertSame(2, $imported->signature_version);
            $normal = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($imported->raw_curl), false, false, false);
            self::assertSame($normal->normalized, $imported->normalized_curl);
            self::assertSame($normal->hash, $imported->curl_hash);
            self::assertSame(FaultConfigurationValidator::defaults(), app(FaultConfigurationValidator::class)->attributes($imported->responses()->sole()->getAttributes()));
            $imported->delete();
        }
    }

    public function test_revision_restore_recovers_new_fields_defaults_missing_null_keys_and_preserves_state(): void
    {
        $endpoint = $this->configured();
        $response = $endpoint->responses()->sole();
        $manager = app(RevisionManager::class);
        $endpointSnapshot = $manager->snapshot($endpoint);
        $responseSnapshot = $manager->snapshot($response);
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => Environment::query()->sole()->id, 'total_match_count' => 8, 'recent_call_digests' => [['method' => 'GET']]]);
        $stateBefore = $state->fresh()->getAttributes();
        $endpointRevision = $manager->record($endpoint, $endpointSnapshot);
        $responseRevision = $manager->record($response, $responseSnapshot);
        $endpoint->update(['excluded_query_params' => [], 'excluded_headers' => [], 'path_pattern_enabled' => false]);
        $response->update(['fault_enabled' => false]);
        $manager->restore($endpointRevision);
        $manager->restore($responseRevision);
        self::assertSame($endpointSnapshot, $manager->snapshot($endpoint->fresh()));
        self::assertSame($responseSnapshot, $manager->snapshot($response->fresh()));
        foreach (['excluded_query_params', 'excluded_headers', 'path_pattern_enabled'] as $key) {
            unset($endpointSnapshot[$key]);
        }
        $endpointSnapshot['signature_version'] = 2;
        foreach (array_keys(FaultConfigurationValidator::defaults()) as $key) {
            $responseSnapshot[$key] = null;
        }
        $manager->restore($manager->record($endpoint->fresh(), $endpointSnapshot));
        $manager->restore($manager->record($response->fresh(), $responseSnapshot));
        self::assertFalse($endpoint->fresh()->path_pattern_enabled);
        self::assertSame([], $endpoint->fresh()->excluded_query_params);
        self::assertSame(FaultConfigurationValidator::defaults(), app(FaultConfigurationValidator::class)->attributes($response->fresh()->getAttributes()));
        self::assertSame($stateBefore, $state->fresh()->getAttributes());
    }

    public function test_preview_rejects_bad_patterns_exclusions_fault_ranges_and_unsupported_faults(): void
    {
        $this->configured();
        $base = json_decode(app(ConfigExporter::class)->export(redactSecrets: false)->toJson(), true);
        $cases = [
            fn (&$doc) => $doc['endpoints'][0]['request']['curl'] = "curl 'https://example.test/users/{bad-name}'",
            fn (&$doc) => $doc['endpoints'][0]['request']['matching']['excluded_headers'] = ['invalid header'],
            fn (&$doc) => $doc['endpoints'][0]['responses'][0]['fault_type'] = 'connection_reset',
            fn (&$doc) => $doc['endpoints'][0]['responses'][0]['fault_delay_ms_max'] = -1,
            fn (&$doc) => $doc['endpoints'][0]['responses'][0]['fault_probability'] = 101,
        ];
        foreach ($cases as $change) {
            $doc = $base;
            $change($doc);
            $validation = app(ConfigValidator::class)->validate(json_encode($doc));
            self::assertFalse($validation->valid());
            self::assertNotEmpty($validation->errors);
        }
    }
}
