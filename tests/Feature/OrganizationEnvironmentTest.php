<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Curl\ParsedCurl;
use App\Services\Environments\EnvironmentContext;
use App\Services\Matching\EndpointMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrganizationEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_endpoints_inherit_the_seeded_default_environment_without_behavior_changes(): void
    {
        $development = Environment::query()->sole();
        self::assertSame('Development', $development->name);
        self::assertTrue($development->is_default);

        $endpoint = MockEndpoint::factory()->create(['enabled' => true]);
        $match = app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://example.test/users', []));

        self::assertNotNull($match);
        self::assertSame($endpoint->id, $match->endpoint->id);
        self::assertFalse($endpoint->environmentOverrides()->exists());
    }

    public function test_matching_respects_only_the_active_environment_override(): void
    {
        $development = Environment::query()->sole();
        $staging = Environment::query()->create(['name' => 'Staging']);
        $endpoint = MockEndpoint::factory()->create(['enabled' => true]);
        $endpoint->environmentOverrides()->attach($staging, ['enabled' => false]);
        $request = new ParsedCurl('GET', 'https://example.test/users', []);

        app(EnvironmentContext::class)->activate($development);
        self::assertNotNull(app(EndpointMatcher::class)->match($request));

        app(EnvironmentContext::class)->activate($staging);
        self::assertNull(app(EndpointMatcher::class)->match($request));

        $endpoint->environmentOverrides()->sync([
            $development->id => ['enabled' => false],
            $staging->id => ['enabled' => true],
        ]);
        self::assertNotNull(app(EndpointMatcher::class)->match($request));

        app(EnvironmentContext::class)->activate($development);
        self::assertNull(app(EndpointMatcher::class)->match($request));

        $endpoint->update(['enabled' => false]);
        app(EnvironmentContext::class)->activate($staging);
        self::assertNull(app(EndpointMatcher::class)->match($request));
    }

    public function test_active_environment_and_endpoint_organization_apis_persist_the_selected_state(): void
    {
        $staging = Environment::query()->create(['name' => 'Staging']);
        $collection = Collection::query()->create(['name' => 'Payments']);
        $tag = Tag::query()->create(['name' => 'Critical']);
        $endpoint = MockEndpoint::factory()->create();

        $this->putJson('/api/active-environment', ['environment_id' => $staging->id])
            ->assertOk()
            ->assertJsonPath('id', $staging->id);
        $this->getJson('/api/active-environment')
            ->assertOk()
            ->assertJsonPath('id', $staging->id);

        $this->patchJson("/api/endpoints/{$endpoint->id}/organization", [
            'collection_id' => $collection->id,
            'tags' => [$tag->id],
            'environment_overrides' => [$staging->id => false],
        ])->assertOk();

        self::assertSame($collection->id, $endpoint->refresh()->collection_id);
        self::assertSame([$tag->id], $endpoint->tags()->pluck('tags.id')->map(static fn ($id): int => (int) $id)->all());
        self::assertFalse((bool) $endpoint->environmentOverrides()->sole()->pivot->enabled);
    }

    public function test_secret_variables_are_write_only_in_api_and_exports(): void
    {
        $environment = Environment::query()->sole();

        $created = $this->postJson("/api/environments/{$environment->id}/variables", [
            'key' => 'API_TOKEN',
            'value' => 'super-secret-token',
            'is_secret' => true,
        ])->assertCreated();
        $created->assertJsonPath('value', '••••••••');
        self::assertStringNotContainsString('super-secret-token', $created->getContent());

        $variableId = (int) $created->json('id');
        $this->patchJson("/api/environments/{$environment->id}/variables/{$variableId}", [
            'key' => 'API_TOKEN_RENAMED',
            'is_secret' => true,
        ])->assertOk()->assertJsonPath('value', '••••••••');
        $this->patchJson("/api/environments/{$environment->id}/variables/{$variableId}", [
            'key' => 'API_TOKEN_RENAMED',
            'value' => '••••••••',
            'is_secret' => false,
        ])->assertUnprocessable();

        $this->getJson('/api/environments')
            ->assertOk()
            ->assertJsonPath('data.0.variables.0.value', '••••••••');

        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        self::assertStringNotContainsString('super-secret-token', $json);
        self::assertStringContainsString('"environment_secrets_redacted": true', $json);
    }

    public function test_deleting_a_collection_keeps_its_endpoints(): void
    {
        $collection = Collection::query()->create(['name' => 'Payments']);
        $endpoint = MockEndpoint::factory()->create(['collection_id' => $collection->id]);

        $this->deleteJson("/api/collections/{$collection->id}")->assertNoContent();

        self::assertDatabaseHas('mock_endpoints', ['id' => $endpoint->id, 'collection_id' => null]);
    }

    public function test_tag_names_are_unique_regardless_of_case(): void
    {
        $this->postJson('/api/tags', ['name' => 'Payments'])->assertCreated();
        $this->postJson('/api/tags', ['name' => 'payments'])->assertUnprocessable();

        self::assertSame(1, Tag::query()->count());
    }

    public function test_duplicate_environment_is_independent_and_masks_copied_secrets(): void
    {
        $source = Environment::query()->sole();
        $source->variables()->create(['key' => 'HOST', 'value' => 'https://one.test', 'is_secret' => false]);
        $source->variables()->create(['key' => 'TOKEN', 'value' => 'copied-secret', 'is_secret' => true]);

        $response = $this->postJson("/api/environments/{$source->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('name', 'Development copy');
        self::assertStringNotContainsString('copied-secret', $response->getContent());

        $copy = Environment::query()->where('name', 'Development copy')->sole();
        $copyHost = $copy->variables()->where('key', 'HOST')->sole();
        $this->patchJson("/api/environments/{$copy->id}/variables/{$copyHost->id}", [
            'key' => 'HOST',
            'value' => 'https://two.test',
            'is_secret' => false,
        ])->assertOk();

        self::assertSame('https://one.test', $source->variables()->where('key', 'HOST')->sole()->value);
        self::assertSame('https://two.test', $copy->variables()->where('key', 'HOST')->sole()->value);
    }

    public function test_environment_scoped_export_uses_override_inheritance_and_redacts_secrets(): void
    {
        $development = Environment::query()->sole();
        $staging = Environment::query()->create(['name' => 'Staging']);
        $staging->variables()->create(['key' => 'PUBLIC_HOST', 'value' => 'https://staging.test', 'is_secret' => false]);
        $staging->variables()->create(['key' => 'PRIVATE_KEY', 'value' => 'never-export-this', 'is_secret' => true]);

        $inherited = MockEndpoint::factory()->create(['name' => 'Inherited']);
        $excluded = MockEndpoint::factory()->create([
            'name' => 'Excluded',
            'raw_curl' => "curl 'https://api.example.test/excluded'",
            'normalized_curl' => "GET\n/excluded\n\n",
            'curl_hash' => hash('sha256', "GET\n/excluded\n\n"),
        ]);
        $included = MockEndpoint::factory()->create([
            'name' => 'Included override',
            'raw_curl' => "curl 'https://api.example.test/included'",
            'normalized_curl' => "GET\n/included\n\n",
            'curl_hash' => hash('sha256', "GET\n/included\n\n"),
        ]);
        $excluded->environmentOverrides()->attach($staging, ['enabled' => false]);
        $included->environmentOverrides()->attach($staging, ['enabled' => true]);
        $excluded->environmentOverrides()->attach($development, ['enabled' => true]);

        $document = json_decode(
            app(ConfigExporter::class)->export(environmentId: $staging->id)->toJson(),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $names = array_column($document['endpoints'], 'name');

        self::assertContains($inherited->name, $names);
        self::assertContains($included->name, $names);
        self::assertNotContains($excluded->name, $names);
        $stagingDocument = collect($document['environments'])->firstWhere('name', 'Staging');
        self::assertSame('https://staging.test', collect($stagingDocument['variables'])->firstWhere('key', 'PUBLIC_HOST')['value']);
        self::assertStringNotContainsString('never-export-this', json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function test_organization_and_environment_configuration_round_trips(): void
    {
        $collection = Collection::query()->create(['name' => 'Payments', 'description' => 'Payment mocks']);
        $tag = Tag::query()->create(['name' => 'Critical']);
        $staging = Environment::query()->create(['name' => 'Staging']);
        $staging->variables()->create(['key' => 'PUBLIC_HOST', 'value' => 'https://staging.test', 'is_secret' => false]);
        $staging->variables()->create(['key' => 'TOKEN', 'value' => 'not-portable', 'is_secret' => true]);
        $endpoint = MockEndpoint::factory()->create(['collection_id' => $collection->id]);
        $endpoint->tags()->attach($tag);
        $endpoint->environmentOverrides()->attach($staging, ['enabled' => false]);

        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();
        $collection->delete();
        $tag->delete();
        $staging->delete();

        $plan = app(ConfigImporter::class)->preview($json, ImportMode::CreateOnly);
        self::assertTrue($plan->canApply(), implode(' ', $plan->errors));
        app(ConfigImporter::class)->apply($plan->token, $plan->digest, true);

        $imported = MockEndpoint::query()->with(['collection', 'tags', 'environmentOverrides'])->sole();
        self::assertSame('Payments', $imported->collection?->name);
        self::assertSame(['Critical'], $imported->tags->pluck('name')->all());
        self::assertSame('Staging', $imported->environmentOverrides->sole()->name);
        self::assertFalse((bool) $imported->environmentOverrides->sole()->pivot->enabled);
        $importedStaging = Environment::query()->where('name', 'Staging')->sole();
        self::assertSame('https://staging.test', $importedStaging->variables()->where('key', 'PUBLIC_HOST')->sole()->value);
        self::assertNull($importedStaging->variables()->where('key', 'TOKEN')->sole()->value);
    }
}
