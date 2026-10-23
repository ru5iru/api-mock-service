<?php

namespace Tests\Feature;

use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Imports\ImportPreviewDiffer;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportPreviewChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_preview_explains_changes_and_retention_without_writing(): void
    {
        $endpoint = MockEndpoint::factory()->create(['name' => 'Before']);
        $response = $endpoint->responses()->create(['body' => 'original']);
        $retained = $endpoint->responses()->create(['body' => 'retain me']);
        $source = app(ConfigExporter::class)->export(redactSecrets: false)->data;
        $importer = app(ConfigImporter::class);
        $unchanged = $importer->preview(json_encode($source), ImportMode::Upsert);
        self::assertSame([], $unchanged->items[0]['changes']);
        $source['endpoints'][0]['name'] = 'After';
        $source['endpoints'][0]['responses'][0]['body'] = 'updated';
        array_pop($source['endpoints'][0]['responses']);
        $plan = $importer->preview(json_encode($source), ImportMode::Upsert);
        $changes = collect($plan->items[0]['changes'])->keyBy('path');
        self::assertSame('Before', $changes['name']['before']);
        self::assertSame('After', $changes['name']['after']);
        self::assertSame('updated', $changes['responses.'.$response->uuid.'.body']['after']);
        self::assertArrayNotHasKey('responses.'.$retained->uuid, $changes);
        self::assertSame('Before', $endpoint->refresh()->name);
        self::assertSame(0, Revision::query()->count());
        $importer->apply($plan->token, $plan->digest, true);
        self::assertSame('After', $endpoint->refresh()->name);
        self::assertSame('updated', $response->refresh()->body);
        self::assertTrue($retained->fresh() !== null);
        $replacement = $importer->preview(json_encode($source), ImportMode::Upsert, true);
        self::assertContains('responses.'.$retained->uuid, array_column($replacement->items[0]['changes'], 'path'));
    }

    public function test_postman_preview_shows_response_replacement_and_matches_the_applied_result(): void
    {
        $source = ['info' => ['name' => 'Preview', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [['name' => 'Users', 'request' => ['method' => 'GET', 'url' => 'https://example.test/users'],
                'response' => [['name' => 'Example', 'code' => 200, 'body' => 'before']]]]];
        $service = app(MappedImportService::class);
        $mapper = app(PostmanMapper::class);
        $create = $service->preview($mapper, json_encode($source), ImportMode::CreateOnly);
        $service->apply($create->token, $create->digest);
        $endpoint = MockEndpoint::query()->sole();
        $source['item'][0]['response'][0]['body'] = 'after';
        $plan = $service->preview($mapper, json_encode($source), ImportMode::Upsert);
        $changes = collect($plan->items[0]['changes'])->keyBy('path');
        self::assertSame('before', $changes['responses.example_1.body']['before']);
        self::assertSame('after', $changes['responses.example_1.body']['after']);
        self::assertStringContainsString('omitted responses are deleted', $plan->items[0]['response_pool_policy']);
        self::assertSame('before', $endpoint->responses()->sole()->body);
        $service->apply($plan->token, $plan->digest);
        self::assertSame('after', $endpoint->responses()->sole()->body);
    }

    public function test_curl_review_redacts_cookie_options_and_json_body_secrets_using_the_existing_parser(): void
    {
        $before = "curl 'https://example.test/users' --cookie 'session=old-cookie' --data-raw '{\"password\":\"old-password\"}'";
        $after = "curl 'https://example.test/users' --cookie 'session=new-cookie' --data-raw '{\"password\":\"new-password\"}'";
        $json = json_encode(app(ImportPreviewDiffer::class)->diff(['request' => ['curl' => $before]], ['request' => ['curl' => $after]]));
        foreach (['old-cookie', 'new-cookie', 'old-password', 'new-password'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertStringContainsString('request.curl', $json);
    }

    public function test_diffs_preserve_change_identity_but_never_expose_secret_values(): void
    {
        $diff = app(ImportPreviewDiffer::class)->diff(
            ['Authorization' => 'Bearer old-secret', 'callback_signing_secret' => 'old-key', 'responses' => ['one' => ['headers' => ['Cookie' => 'old-session-value']]]],
            ['Authorization' => 'Bearer new-secret', 'callback_signing_secret' => 'new-key', 'responses' => ['one' => ['headers' => ['Cookie' => 'new-session-value']]]],
        );
        self::assertCount(3, $diff);
        $json = json_encode($diff);
        self::assertStringContainsString('REDACTED', $json);
        foreach (['old-secret', 'new-secret', 'old-key', 'new-key', 'old-session-value', 'new-session-value'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }
}
