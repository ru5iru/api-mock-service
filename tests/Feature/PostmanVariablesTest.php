<?php

namespace Tests\Feature;

use App\Livewire\Admin\ConfigTransfer;
use App\Livewire\Admin\EndpointForm;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Environments\EnvironmentContext;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Imports\PostmanResponseVariables;
use App\Services\Templates\FakerMethodCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

final class PostmanVariablesTest extends TestCase
{
    use RefreshDatabase;

    private function document(array $items, array $variables = []): string
    {
        return json_encode(['info' => ['name' => 'Variables', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'], 'variable' => $variables, 'item' => $items], JSON_THROW_ON_ERROR);
    }

    private function item(array $extra = []): array
    {
        return ['name' => 'Cart', 'request' => ['method' => 'GET', 'url' => '/cart?login={{loginId}}', 'header' => [['key' => 'Authorization', 'value' => 'Bearer {{stg_token}}']]], 'response' => [], ...$extra];
    }

    public function test_embedded_and_unresolved_variables_are_unique_with_environment_file_precedence(): void
    {
        $mapper = app(PostmanMapper::class)->withVariableOptions(['environment_file_values' => [['key' => 'cartId', 'value' => 'environment']]]);
        $mapped = $mapper->map($this->document([$this->item()], [['key' => 'cartId', 'value' => 'collection']]));
        $candidates = collect($mapped['variable_candidates'])->keyBy('name');
        self::assertSame(['cartId', 'loginId', 'stg_token'], $candidates->keys()->all());
        self::assertSame('environment', $candidates['cartId']['value']);
        self::assertFalse($candidates['stg_token']['known']);
        self::assertTrue($candidates['stg_token']['is_secret']);
        self::assertStringContainsString('Environment file overrides embedded variable cartId', implode(' ', $mapped['warnings']));
    }

    public function test_folder_variables_do_not_supply_values_to_sibling_requests(): void
    {
        $mapped = app(PostmanMapper::class)->map($this->document([
            ['name' => 'Scoped', 'variable' => [['key' => 'onlyHere', 'value' => 'folder']], 'item' => [$this->item()]],
            $this->item(),
        ]));
        $candidate = collect($mapped['variable_candidates'])->firstWhere('name', 'onlyHere');
        self::assertSame(['Scoped', 'Scoped/Cart'], $candidate['sources']);
    }

    public function test_original_tokens_survive_import_and_are_visible_in_matching(): void
    {
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $this->document([$this->item()]), ImportMode::CreateOnly);
        self::assertStringContainsString('{{stg_token}}', implode(' ', $plan->items[0]['warnings']));
        $service->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame(['{{stg_token}}'], $endpoint->external_source['variable_provenance'][0]['tokens']);
        Livewire::test(EndpointForm::class, ['endpoint' => $endpoint])->set('activeTab', 'matching')->assertSee('{{stg_token}}');
    }

    public function test_reviewed_creation_is_explicit_encrypted_and_does_not_overwrite_existing_keys(): void
    {
        $environment = app(EnvironmentContext::class)->active();
        $environment->variables()->create(['key' => 'cartId', 'value' => 'keep-existing', 'is_secret' => false]);
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $this->document([$this->item()], [['key' => 'cartId', 'value' => 'candidate']]), ImportMode::CreateOnly);
        self::assertSame(1, $environment->variables()->count());
        $result = $service->createVariables($plan->token, $plan->digest, $environment);
        self::assertSame(['created' => 2, 'skipped' => 1], $result);
        $secret = $environment->variables()->where('key', 'stg_token')->sole();
        self::assertTrue($secret->is_secret);
        self::assertSame('', $secret->value);
        self::assertNotSame('', $secret->getRawOriginal('value'));
        self::assertSame('keep-existing', $environment->variables()->where('key', 'cartId')->sole()->value);
    }

    public function test_resolve_matches_environment_snapshot_with_per_field_exclusion_override(): void
    {
        $mapper = app(PostmanMapper::class)->withVariableOptions(['mode' => 'resolve', 'environment_values' => ['stg_token' => 'live-token', 'loginId' => '42']]);
        $mapped = $mapper->map($this->document([$this->item()]));
        self::assertSame([], $mapped['items'][0]['excluded_headers']);
        self::assertSame([], $mapped['items'][0]['excluded_query_params']);
        $fields = $mapped['items'][0]['variable_provenance'];
        $mapper = app(PostmanMapper::class)->withVariableOptions(['mode' => 'resolve', 'environment_values' => ['stg_token' => 'live-token', 'loginId' => '42'], 'field_overrides' => [$fields[1]['id'] => 'exclude']]);
        $plan = app(MappedImportService::class)->preview($mapper, $this->document([$this->item()]), ImportMode::CreateOnly);
        app(MappedImportService::class)->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame(['login'], $endpoint->excluded_query_params);
        self::assertStringContainsString('authorization:Bearer live-token', $endpoint->normalized_curl);
        self::assertStringNotContainsString('loginId', $endpoint->normalized_curl);
    }

    public function test_missing_resolution_values_block_apply_and_secret_values_never_enter_public_preview(): void
    {
        $mapper = app(PostmanMapper::class)->withVariableOptions(['mode' => 'resolve', 'environment_file_values' => [['key' => 'stg_token', 'value' => 'DO_NOT_LEAK']]]);
        $plan = app(MappedImportService::class)->preview($mapper, $this->document([$this->item()]), ImportMode::CreateOnly);
        self::assertFalse($plan->canApply());
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($plan->toArray()));
        $this->expectException(\InvalidArgumentException::class);
        app(MappedImportService::class)->apply($plan->token, $plan->digest);
    }

    public function test_companion_upload_and_creation_work_through_livewire(): void
    {
        $environment = app(EnvironmentContext::class)->active();
        $component = Livewire::test(ConfigTransfer::class)->call('selectFormat', 'postman')
            ->set('configFile', UploadedFile::fake()->createWithContent('collection.json', $this->document([$this->item()])))
            ->set('environmentFile', UploadedFile::fake()->createWithContent('environment.json', json_encode(['values' => [['key' => 'stg_token', 'value' => 'ENV_SECRET']]])))
            ->set('variableEnvironmentId', (string) $environment->id)->call('preview')->assertHasNoErrors()
            ->assertSee('Create these as Environment variables')->assertDontSee('ENV_SECRET');
        self::assertSame(0, $environment->variables()->count());
        $component->call('createEnvironmentVariables')->assertHasNoErrors();
        self::assertSame('ENV_SECRET', $environment->variables()->where('key', 'stg_token')->sole()->value);
        $component->set('variableMode', 'resolve')->assertHasNoErrors()->call('apply')->assertHasNoErrors();
        self::assertSame([], MockEndpoint::query()->sole()->excluded_headers);
    }

    public function test_response_environment_tokens_render_dynamically_while_request_body_stays_literal(): void
    {
        $environment = app(EnvironmentContext::class)->active();
        $environment->variables()->create(['key' => 'cartId', 'value' => 'live-cart', 'is_secret' => false]);
        $document = $this->document([$this->item([
            'request' => ['method' => 'POST', 'url' => '/cart', 'body' => ['mode' => 'raw', 'raw' => '{"cart":"{{cartId}}"}']],
            'response' => [['name' => 'Example', 'code' => 200, 'body' => '{"cart":"{{cartId}}"}']],
        ])]);
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $document, ImportMode::CreateOnly);
        self::assertStringContainsString('switched to Template mode: 1 variable(s)', implode(' ', $plan->items[0]['warnings']));
        $service->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        $response = $endpoint->responses()->sole();
        self::assertSame('template', $response->body_mode);
        self::assertStringContainsString('{{env.cartId}}', $response->body);
        self::assertStringContainsString('{{cartId}}', $endpoint->normalized_curl);
        $this->call('POST', '/cart', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"cart":"{{cartId}}"}')->assertOk()->assertJsonPath('cart', 'live-cart');
        $environment->variables()->where('key', 'cartId')->first()->update(['value' => 'changed']);
        $this->call('POST', '/cart', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"cart":"{{cartId}}"}')->assertOk()->assertJsonPath('cart', 'changed');
    }

    public function test_dynamic_mapping_uses_catalog_and_unmapped_tokens_remain_literal(): void
    {
        $body = '{"email":"{{$randomEmail}}","unknown":"{{$someObscureVar}}","uuid":"{{$guid}}","integer":"{{$randomInt}}","epoch":"{{$timestamp}}","iso":"{{$isoTimestamp}}"}';
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $this->document([$this->item(['request' => ['method' => 'GET', 'url' => '/dynamic'], 'response' => [['name' => 'Example', 'code' => 200, 'body' => $body]]])]), ImportMode::CreateOnly);
        self::assertStringContainsString('Unmapped Postman dynamic variable $someObscureVar', implode(' ', $plan->items[0]['warnings']));
        $service->apply($plan->token, $plan->digest);
        $output = $this->get('/dynamic')->assertOk()->json();
        self::assertNotFalse(filter_var($output['email'], FILTER_VALIDATE_EMAIL));
        self::assertSame('{{$someObscureVar}}', $output['unknown']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $output['uuid']);
        self::assertGreaterThanOrEqual(0, (int) $output['integer']);
        self::assertLessThanOrEqual(1000, (int) $output['integer']);
        self::assertEqualsWithDelta(time(), (int) $output['epoch'], 3);
        self::assertEqualsWithDelta(time(), strtotime($output['iso']), 3);
        foreach (app(PostmanResponseVariables::class)->mappings() as $method) {
            self::assertSame('current', app(FakerMethodCatalog::class)->resolve(explode('(', $method)[0])['status']);
        }
    }

    public function test_text_examples_keep_their_text_media_type_and_unknown_only_examples_stay_static(): void
    {
        $environment = app(EnvironmentContext::class)->active();
        $environment->variables()->create(['key' => 'cartId', 'value' => '42', 'is_secret' => false]);
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $this->document([
            $this->item(['request' => ['method' => 'GET', 'url' => '/text'], 'response' => [['name' => 'Text', 'code' => 200, 'header' => [['key' => 'Content-Type', 'value' => 'text/plain']], 'body' => 'Cart {{cartId}} / {{$randomEmail}}']]]),
            $this->item(['name' => 'Unknown', 'request' => ['method' => 'GET', 'url' => '/unknown'], 'response' => [['name' => 'Unknown', 'code' => 200, 'body' => '{{$someObscureVar}}']]]),
        ]), ImportMode::CreateOnly);
        $service->apply($plan->token, $plan->digest);
        $reply = $this->get('/text')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        self::assertMatchesRegularExpression('/^Cart 42 \/ .+@.+$/', $reply->getContent());
        self::assertSame('static', MockEndpoint::query()->where('name', 'Unknown')->sole()->responses()->sole()->body_mode);
        $this->get('/unknown')->assertOk()->assertContent('{{$someObscureVar}}');
    }

    public function test_provenance_and_rewritten_templates_survive_native_round_trip(): void
    {
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $this->document([$this->item(['response' => [['name' => 'Saved', 'code' => 200, 'body' => '{"cart":"{{cartId}}"}']]])]), ImportMode::CreateOnly);
        $service->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        $source = $endpoint->external_source;
        $template = $endpoint->responses()->sole()->template;
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        $endpoint->delete();
        $importer = app(ConfigImporter::class);
        $plan = $importer->preview($json, ImportMode::CreateOnly);
        self::assertSame([], $plan->errors);
        $importer->apply($plan->token, $plan->digest, true);
        self::assertSame($source, MockEndpoint::query()->sole()->external_source);
        self::assertSame($template, MockEndpoint::query()->sole()->responses()->sole()->template);
    }
}
