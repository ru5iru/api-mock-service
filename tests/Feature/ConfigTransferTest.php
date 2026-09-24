<?php

namespace Tests\Feature;

use App\Livewire\Admin\ConfigTransfer;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class ConfigTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_config_dashboard_is_available_through_access_middleware(): void
    {
        $this->get('/dashboard/config')
            ->assertOk()
            ->assertSee('Import / export')
            ->assertSee('Redact request secrets');
    }

    public function test_create_only_is_the_selected_safe_default(): void
    {
        $component = Livewire::test(ConfigTransfer::class)
            ->assertSet('mode', ImportMode::CreateOnly->value)
            ->assertSee('Create only')
            ->assertSee('Safe default');

        self::assertSame(1, substr_count($component->html(), ' checked'));
        self::assertStringContainsString('value="create-only" checked', $component->html());
    }

    public function test_transfer_ui_uses_derived_endpoint_names_and_search(): void
    {
        MockEndpoint::factory()->create([
            'name' => null,
            'method' => 'GET',
            'normalized_curl' => "GET\n/v1/customers?active=1\n\n",
        ]);
        MockEndpoint::factory()->create(['name' => 'Orders endpoint']);

        Livewire::test(ConfigTransfer::class)
            ->assertSee('GET /v1/customers')
            ->assertSee('Orders endpoint')
            ->set('exportSearch', 'customers')
            ->assertSee('GET /v1/customers')
            ->assertDontSee('Orders endpoint');
    }

    public function test_import_file_is_parsed_immediately_and_can_be_removed(): void
    {
        Livewire::test(ConfigTransfer::class)
            ->set('configFile', UploadedFile::fake()->createWithContent('invalid.json', '{broken'))
            ->assertHasErrors(['configFile'])
            ->call('removeFile')
            ->assertSet('configFile', null)
            ->assertHasNoErrors(['configFile']);
    }

    public function test_import_conflict_recovery_can_switch_to_clone_mode(): void
    {
        Livewire::test(ConfigTransfer::class)
            ->set('mode', 'create-only')
            ->call('switchToClone')
            ->assertSet('mode', 'clone')
            ->assertSet('plan', []);
    }

    public function test_export_is_deterministic_and_redacts_request_secrets(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00 UTC');
        $endpoint = MockEndpoint::factory()->create([
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
            'enabled' => true,
            'raw_curl' => "curl 'https://api.example.test/users?api_key=abc123&view=full' -H 'Authorization: Bearer super-secret' -H 'X-Trace: keep-me'",
        ]);
        $endpoint->responses()->create([
            'uuid' => '018f37a0-5100-7b64-9df0-9a1f5f90795c',
            'status_code' => 200,
            'headers' => ['X-Zeta' => 'last', 'Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
            'delay_ms' => 0,
            'weight' => 1,
        ]);

        $exporter = app(ConfigExporter::class);
        $first = $exporter->export()->toJson();
        $second = $exporter->export()->toJson();

        self::assertSame($first, $second);
        self::assertStringNotContainsString('super-secret', $first);
        self::assertStringNotContainsString('abc123', $first);
        self::assertStringContainsString('keep-me', $first);

        $document = json_decode($first, true, 64, JSON_THROW_ON_ERROR);
        self::assertFalse($document['endpoints'][0]['enabled']);
        self::assertTrue($document['endpoints'][0]['requires_secret_replacement']);
        self::assertSame(
            ['Content-Type', 'X-Zeta'],
            array_keys($document['endpoints'][0]['responses'][0]['headers']),
        );
    }

    public function test_create_only_import_and_uuid_upsert_are_atomic_and_idempotent(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'name' => 'Portable endpoint',
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
            'raw_curl' => "curl 'https://api.example.test/users'",
        ]);
        $endpoint->responses()->create([
            'uuid' => '018f37a0-5100-7b64-9df0-9a1f5f90795c',
            'status_code' => 202,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"queued":true}',
            'delay_ms' => 15,
            'weight' => 3,
        ]);

        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();

        $importer = app(ConfigImporter::class);
        $createPlan = $importer->preview($json, ImportMode::CreateOnly);
        self::assertTrue($createPlan->canApply());

        $createSummary = $importer->apply($createPlan->token, $createPlan->digest, true);
        self::assertSame(1, $createSummary->endpointsCreated);
        self::assertSame(1, $createSummary->responsesCreated);
        $this->assertDatabaseHas('mock_endpoints', [
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
            'name' => 'Portable endpoint',
        ]);

        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $data['endpoints'][0]['name'] = 'Renamed by import';
        $data['endpoints'][0]['normalized_curl'] = 'forged canonical request';
        $data['endpoints'][0]['curl_hash'] = str_repeat('0', 64);
        $updatedJson = json_encode($data, JSON_THROW_ON_ERROR);

        foreach ([1, 2] as $attempt) {
            $upsertPlan = $importer->preview($updatedJson, ImportMode::Upsert);
            self::assertTrue($upsertPlan->canApply(), "Upsert attempt {$attempt} should be valid.");
            $importer->apply($upsertPlan->token, $upsertPlan->digest, true);
        }

        self::assertSame(1, MockEndpoint::query()->count());
        self::assertSame('Renamed by import', MockEndpoint::query()->sole()->name);
        self::assertNotSame(str_repeat('0', 64), MockEndpoint::query()->sole()->curl_hash);
        self::assertNotSame('forged canonical request', MockEndpoint::query()->sole()->normalized_curl);
        self::assertSame(1, MockEndpoint::query()->sole()->responses()->count());
    }

    public function test_exact_signature_conflict_prevents_every_write(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'name' => 'Existing endpoint',
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
        ]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $data['endpoints'][0]['uuid'] = (string) Str::uuid7();
        $conflictingJson = json_encode($data, JSON_THROW_ON_ERROR);

        $plan = app(ConfigImporter::class)->preview($conflictingJson, ImportMode::CreateOnly);

        self::assertFalse($plan->canApply());
        self::assertSame(1, $plan->counts['conflicts']);
        self::assertSame(1, MockEndpoint::query()->count());
        self::assertSame('Existing endpoint', MockEndpoint::query()->sole()->name);
    }

    public function test_overlapping_generic_and_specific_signatures_are_warned_before_apply(): void
    {
        $parser = app(CurlParser::class);
        $hasher = app(CurlHasher::class);
        $specificCurl = "curl 'https://api.example.test/overlap' -H 'X-Tenant: one'";
        $specific = $hasher->forOptions($parser->parse($specificCurl), false, false, false);
        MockEndpoint::factory()->create([
            'name' => 'Specific local endpoint',
            'raw_curl' => $specificCurl,
            'normalized_curl' => $specific->normalized,
            'curl_hash' => $specific->hash,
            'exclude_headers' => false,
        ]);

        $genericCurl = "curl 'https://another-origin.test/overlap' -H 'X-Tenant: two'";
        $generic = $hasher->forOptions($parser->parse($genericCurl), false, false, true);
        $imported = MockEndpoint::factory()->create([
            'name' => 'Generic imported endpoint',
            'raw_curl' => $genericCurl,
            'normalized_curl' => $generic->normalized,
            'curl_hash' => $generic->hash,
            'exclude_headers' => true,
        ]);
        $json = app(ConfigExporter::class)->export([$imported->uuid], false)->toJson();
        $imported->delete();

        $plan = app(ConfigImporter::class)->preview($json, ImportMode::CreateOnly);

        self::assertTrue($plan->canApply());
        self::assertStringContainsString('overlaps Specific local endpoint', implode(' ', $plan->warnings));
        self::assertStringContainsString('predicted winner', implode(' ', $plan->warnings));
    }

    public function test_redacted_import_requires_acknowledgement_and_remains_disabled(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
            'enabled' => true,
            'raw_curl' => "curl 'https://api.example.test/users' -H 'Authorization: Bearer remove-me'",
        ]);
        $json = app(ConfigExporter::class)->export()->toJson();
        $endpoint->delete();

        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, ImportMode::CreateOnly);
        self::assertTrue($plan->canApply());
        self::assertNotEmpty($plan->warnings);

        try {
            $importer->apply($plan->token, $plan->digest);
            self::fail('Warnings should require explicit acknowledgement.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Acknowledge', $exception->getMessage());
        }

        $summary = $importer->apply($plan->token, $plan->digest, true);
        self::assertSame(1, $summary->disabled);
        self::assertFalse(MockEndpoint::query()->sole()->enabled);
    }

    public function test_http_preview_and_apply_use_the_same_import_pipeline(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
        ]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();

        $preview = $this->post(
            '/dashboard/config/imports/preview',
            [
                'config' => UploadedFile::fake()->createWithContent('config.json', $json),
                'mode' => 'create-only',
            ],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('can_apply', true);

        $this->postJson('/dashboard/config/imports/apply', [
            'token' => $preview->json('token'),
            'digest' => $preview->json('digest'),
            'acknowledge_warnings' => true,
        ])->assertOk()->assertJsonPath('summary.endpoints_created', 1);

        self::assertSame(1, MockEndpoint::query()->count());
    }

    public function test_http_export_uses_the_previewed_filename_convention(): void
    {
        Carbon::setTestNow('2026-09-20 12:00:00 UTC');
        MockEndpoint::factory()->create();

        $this->post('/dashboard/config/exports', ['redact_secrets' => true])
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="mockdeck-export-20260920.json"');
    }

    public function test_cli_dry_run_does_not_write_and_apply_imports_the_document(): void
    {
        $endpoint = MockEndpoint::factory()->create([
            'uuid' => '018f37a0-10db-7c76-8791-e3d9b3849421',
        ]);
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();
        $path = storage_path('framework/testing/mockdeck-import-test.json');
        file_put_contents($path, $json);

        try {
            self::assertSame(0, Artisan::call('mockdeck:import', [
                'file' => $path,
                '--dry-run' => true,
            ]));
            self::assertSame(0, MockEndpoint::query()->count());

            self::assertSame(0, Artisan::call('mockdeck:import', [
                'file' => $path,
                '--acknowledge-warnings' => true,
            ]));
            self::assertSame(1, MockEndpoint::query()->count());
        } finally {
            @unlink($path);
        }
    }
}
