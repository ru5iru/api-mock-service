<?php

namespace Tests\Feature;

use App\Livewire\Admin\ConfigTransfer;
use App\Models\Collection;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Config\ImportSummary;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Matching\EndpointMatcher;
use App\Services\Revisions\RevisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

final class PostmanImportTest extends TestCase
{
    use RefreshDatabase;

    private function document(array $items, array $extra = []): string
    {
        return json_encode(['info' => ['name' => 'Example API', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'], 'item' => $items, ...$extra], JSON_THROW_ON_ERROR);
    }

    private function item(string $path = '/users', array $extra = []): array
    {
        return ['name' => 'Users', 'request' => ['method' => 'GET', 'url' => 'https://example.test'.$path], ...$extra];
    }

    private function import(string $json, ImportMode $mode = ImportMode::CreateOnly): ImportSummary
    {
        $service = app(MappedImportService::class);
        $plan = $service->preview(app(PostmanMapper::class), $json, $mode);
        self::assertSame([], $plan->errors);

        return $service->apply($plan->token, $plan->digest);
    }

    public function test_nested_folders_flatten_and_empty_folders_do_not_fabricate_endpoints(): void
    {
        $this->import($this->document([$this->item('/root'), ['name' => 'Auth', 'item' => [$this->item('/auth'), ['name' => 'Login', 'item' => [$this->item('/login')]], ['name' => 'Empty', 'item' => []]]]]));
        self::assertSame(['Auth', 'Auth / Empty', 'Auth / Login', 'Example API'], Collection::query()->orderBy('name')->pluck('name')->all());
        self::assertSame(3, MockEndpoint::query()->count());
        self::assertSame(0, MockEndpoint::query()->first()->responses()->count());
        self::assertSame('postman', MockEndpoint::query()->first()->external_source['type']);
    }

    public function test_variable_exclusions_and_path_parameters_match_real_changed_values(): void
    {
        $json = $this->document([$this->item(extra: ['request' => ['method' => 'GET', 'url' => ['host' => ['ignored'], 'port' => 'bad', 'path' => ['users', '{{id}}'], 'query' => [['key' => 'nonce', 'value' => '{{nonce}}'], ['key' => 'kind', 'value' => 'book']]], 'header' => [['key' => 'X-Trace', 'value' => '{{trace}}'], ['key' => 'X-Version', 'value' => '2'], ['key' => 'Ignored', 'value' => 'yes', 'disabled' => true]]], 'response' => [['name' => 'Success', 'code' => 200, 'body' => '{"ok":true}']]])]);
        $plan = app(MappedImportService::class)->preview(app(PostmanMapper::class), $json, ImportMode::CreateOnly);
        self::assertStringContainsString('excluded: contains a Postman variable', implode(' ', $plan->items[0]['warnings']));
        app(MappedImportService::class)->apply($plan->token, $plan->digest);
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame(['nonce'], $endpoint->excluded_query_params);
        self::assertSame(['x-trace'], $endpoint->excluded_headers);
        self::assertTrue($endpoint->path_pattern_enabled);
        self::assertSame("GET\n/users/{id}?kind=book\nx-version:2\n\n", $endpoint->normalized_curl);
        $match = app(EndpointMatcher::class)->match(new ParsedCurl('GET', 'https://actual.test/users/42?nonce=live&kind=book', [['name' => 'X-Trace', 'value' => 'live'], ['name' => 'X-Version', 'value' => '2']]));
        self::assertTrue($match->endpoint->is($endpoint));
        self::assertSame('V6', $endpoint->signatureVariant());
    }

    public function test_malformed_json_body_is_preserved_and_scripts_only_produce_warnings(): void
    {
        $body = '{"id":42} // trailing comment {{value}}';
        $script = 'throw new Error("MUST_NEVER_RUN");';
        $json = $this->document([['name' => 'Folder', 'item' => [$this->item(extra: ['request' => ['method' => 'POST', 'url' => 'https://a.test/users', 'body' => ['mode' => 'raw', 'raw' => $body]]])]]], ['event' => [['listen' => 'prerequest', 'script' => ['exec' => [$script, 'pm.sendRequest("https://forbidden.test");']]], ['listen' => 'test', 'script' => ['exec' => ['pm.test("never");']]]]]);
        $mapped = app(PostmanMapper::class)->map($json);
        $warnings = implode(' ', $mapped['items'][0]['warnings']);
        self::assertStringContainsString('Body is not valid JSON — matched as raw text', $warnings);
        self::assertStringContainsString('Pre-request script present (2 lines); not imported or executed', $warnings);
        self::assertStringContainsString('Test script present (1 lines)', $warnings);
        self::assertStringContainsString('Body contains Postman variables', $warnings);
        self::assertStringNotContainsString('MUST_NEVER_RUN', json_encode($mapped));
        $this->import($json);
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame($body, app(CurlParser::class)->parse($endpoint->raw_curl)->body);
        self::assertStringContainsString($body, $endpoint->normalized_curl);
    }

    public function test_auth_inheritance_translation_and_unsupported_auth(): void
    {
        $auth = static fn ($type, $values) => ['type' => $type, $type => array_map(static fn ($key, $value) => compact('key', 'value'), array_keys($values), $values)];
        $items = [
            ['name' => 'Folder', 'auth' => $auth('bearer', ['token' => '{{stg_token}}']), 'item' => [$this->item('/inherited')]],
            $this->item('/basic', ['request' => ['url' => '/basic', 'auth' => $auth('basic', ['username' => 'alice', 'password' => 'password'])]]),
            $this->item('/variable-basic', ['request' => ['url' => '/variable-basic', 'auth' => $auth('basic', ['username' => '{{user}}', 'password' => '{{pass}}'])]]),
            $this->item('/key', ['request' => ['url' => '/key', 'auth' => $auth('apikey', ['key' => 'X-Key', 'value' => 'secret', 'in' => 'header'])]]),
            $this->item('/query', ['request' => ['url' => '/query', 'auth' => $auth('apikey', ['key' => 'api_key', 'value' => '{{key}}', 'in' => 'query'])]]),
            $this->item('/oauth', ['request' => ['url' => '/oauth', 'auth' => ['type' => 'oauth2']]]),
        ];
        $mapped = collect(app(PostmanMapper::class)->map($this->document($items))['items'])->keyBy('path');
        self::assertSame('Bearer {{stg_token}}', app(CurlParser::class)->parse($mapped['/inherited']['raw_curl'])->headers[0]['value']);
        self::assertSame(['authorization'], $mapped['/inherited']['excluded_headers']);
        self::assertSame('Basic '.base64_encode('alice:password'), app(CurlParser::class)->parse($mapped['/basic']['raw_curl'])->headers[0]['value']);
        self::assertSame(['authorization'], $mapped['/variable-basic']['excluded_headers']);
        self::assertSame('X-Key', app(CurlParser::class)->parse($mapped['/key']['raw_curl'])->headers[0]['name']);
        self::assertSame(['api_key'], $mapped['/query']['excluded_query_params']);
        self::assertSame([], app(CurlParser::class)->parse($mapped['/oauth']['raw_curl'])->headers);
        self::assertStringContainsString('Auth type oauth2 was not translated', implode(' ', $mapped['/oauth']['warnings']));
    }

    public function test_body_modes_and_raw_url_fallback_preserve_meaning_without_reading_files(): void
    {
        $bodies = [
            ['mode' => 'urlencoded', 'urlencoded' => [['key' => 'a', 'value' => 'two words'], ['key' => 'disabled', 'value' => 'x', 'disabled' => true]]],
            ['mode' => 'formdata', 'formdata' => [['key' => 'photo', 'type' => 'file', 'src' => '/etc/passwd'], ['key' => 'name', 'value' => 'Ada']]],
            ['mode' => 'graphql', 'graphql' => ['query' => '{ viewer { id } }', 'variables' => '{"id":1}']],
        ];
        $items = array_map(fn ($body) => $this->item(extra: ['request' => ['method' => 'POST', 'url' => ['raw' => 'https://{{host}}:{{port}}/v{{version}}/{{id}}?nonce={{nonce}}'], 'body' => $body]]), $bodies);
        $mapped = app(PostmanMapper::class)->map($this->document($items))['items'];
        self::assertSame('/v%7B%7Bversion%7D%7D/{id}', $mapped[0]['path']);
        self::assertSame('a=two%20words', app(CurlParser::class)->parse($mapped[0]['raw_curl'])->body);
        self::assertStringContainsString('Partial-segment', implode(' ', $mapped[0]['warnings']));
        self::assertStringContainsString('File upload photo', implode(' ', $mapped[1]['warnings']));
        self::assertSame('photo=%5Bfile%20content%20not%20included%5D&name=Ada', app(CurlParser::class)->parse($mapped[1]['raw_curl'])->body);
        self::assertSame(['query' => '{ viewer { id } }', 'variables' => ['id' => 1]], json_decode(app(CurlParser::class)->parse($mapped[2]['raw_curl'])->body, true));
    }

    public function test_examples_are_weighted_labelled_and_stale_original_request_does_not_change_matching(): void
    {
        $json = $this->document([$this->item(extra: ['response' => [
            ['name' => '2026-08-18', 'code' => 201, 'header' => [['key' => 'Content-Type', 'value' => 'application/json']], 'body' => '{"ok":true}', 'originalRequest' => ['url' => '/old', 'body' => ['mode' => 'raw', 'raw' => 'different']]],
            ['name' => 'Failure', 'code' => 503, 'body' => 'unavailable'],
        ]])]);
        $mapped = app(PostmanMapper::class)->map($json);
        self::assertStringContainsString('This example may be stale', implode(' ', $mapped['items'][0]['warnings']));
        $this->import($json);
        $endpoint = MockEndpoint::query()->sole();
        self::assertSame('weighted', $endpoint->selection_mode);
        self::assertSame([201, 503], $endpoint->responses->pluck('status_code')->all());
        self::assertSame([1, 1], $endpoint->responses->pluck('weight')->all());
        self::assertSame(['2026-08-18', 'Failure'], $endpoint->responses->pluck('external_label')->all());
        self::assertSame(['Content-Type' => 'application/json'], $endpoint->responses[0]->headers);
        self::assertSame(['ok' => true], json_decode($endpoint->responses[0]->body, true));
        self::assertStringContainsString('/users', $endpoint->normalized_curl);
        self::assertStringNotContainsString('different', $endpoint->normalized_curl);
    }

    public function test_create_skip_update_batch_undo_and_clone_preserve_runtime_state(): void
    {
        $original = $this->document([$this->item(extra: ['response' => [['name' => 'Old', 'code' => 200, 'body' => 'old']]])]);
        $this->import($original);
        self::assertSame(0, $this->import($original)->endpointsCreated);
        $endpoint = MockEndpoint::query()->sole();
        $state = EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => Environment::query()->first()->id, 'total_match_count' => 7]);
        $beforeEndpoint = app(RevisionManager::class)->snapshot($endpoint);
        $beforeResponse = app(RevisionManager::class)->snapshot($endpoint->responses()->sole());
        $new = $this->document([$this->item(extra: ['response' => [['name' => 'New', 'code' => 202, 'body' => 'new'], ['name' => 'Additional', 'code' => 500, 'body' => 'bad']]])]);
        $summary = $this->import($new, ImportMode::Upsert);
        self::assertSame(1, $summary->endpointsUpdated);
        self::assertSame(2, $endpoint->responses()->count());
        self::assertSame(1, Revision::query()->where('import_batch_id', $summary->importBatchId)->count());
        app(RevisionManager::class)->undoImport($summary->importBatchId);
        self::assertSame($beforeEndpoint, app(RevisionManager::class)->snapshot($endpoint->fresh()));
        self::assertSame($beforeResponse, app(RevisionManager::class)->snapshot($endpoint->responses()->sole()));
        self::assertSame(7, $state->fresh()->total_match_count);
        self::assertSame(1, $this->import($original, ImportMode::Clone)->endpointsCreated);
        self::assertSame(2, MockEndpoint::query()->count());
        self::assertSame(1, EndpointCallState::query()->count());
    }

    public function test_native_round_trip_preserves_provenance_and_older_files_default_null(): void
    {
        $this->import($this->document([$this->item(extra: ['response' => [['name' => 'Example', 'code' => 200, 'body' => 'ok']]])]));
        $source = MockEndpoint::query()->sole()->external_source;
        $json = app(ConfigExporter::class)->export(redactSecrets: false)->toJson();
        self::assertSame('1.5', json_decode($json, true)['format_version']);
        MockEndpoint::query()->delete();
        $plan = app(ConfigImporter::class)->preview($json, ImportMode::CreateOnly);
        self::assertSame([], $plan->errors);
        app(ConfigImporter::class)->apply($plan->token, $plan->digest, true);
        self::assertSame($source, MockEndpoint::query()->sole()->external_source);
        self::assertSame('Example', MockEndpoint::query()->sole()->responses()->sole()->external_label);
        $old = json_decode($json, true);
        $old['format_version'] = '1.4';
        $plan = app(ConfigImporter::class)->preview(json_encode($old), ImportMode::Upsert);
        app(ConfigImporter::class)->apply($plan->token, $plan->digest, true);
        self::assertNull(MockEndpoint::query()->sole()->external_source);
        self::assertNull(MockEndpoint::query()->sole()->responses()->sole()->external_label);
    }

    public function test_livewire_preview_masks_shared_credentials_in_request_and_examples_and_warnings_do_not_block(): void
    {
        $json = $this->document([$this->item(extra: ['request' => ['url' => '/users?api_key=secret-query', 'header' => [['key' => 'Cookie', 'value' => 'secret-cookie'], ['key' => 'Authorization', 'value' => 'Bearer secret-token']]], 'response' => [['name' => 'Cookie', 'code' => 200, 'header' => [['key' => 'Set-Cookie', 'value' => 'secret-response']], 'body' => 'ok']]])]);
        Livewire::test(ConfigTransfer::class)->call('selectFormat', 'postman')
            ->set('configFile', UploadedFile::fake()->createWithContent('collection.json', $json))
            ->call('preview')->assertHasNoErrors()->assertSee('Possible credentials detected')
            ->assertSee('Example API')->call('maskSecrets')->assertSee('Detected credentials are masked')
            ->assertSet('acknowledgeWarnings', false)->call('apply')->assertHasNoErrors();
        $endpoint = MockEndpoint::query()->sole();
        self::assertStringNotContainsString('secret-', $endpoint->raw_curl);
        self::assertStringContainsString('REPLACE_ME', $endpoint->raw_curl);
        self::assertSame('REPLACE_ME', $endpoint->responses()->sole()->headers['Set-Cookie']);
    }

    public function test_undo_restores_deleted_examples_rules_and_callback_secrets(): void
    {
        $original = $this->document([$this->item(extra: ['response' => [['name' => 'One', 'code' => 200], ['name' => 'Two', 'code' => 500]]])]);
        $this->import($original);
        $endpoint = MockEndpoint::query()->sole();
        $endpoint->update(['selection_mode' => 'rule']);
        $responses = $endpoint->responses;
        $responses[0]->update(['callback_enabled' => true, 'callback_url' => 'https://callback.test/', 'callback_signing_enabled' => true, 'callback_signing_secret' => 'restore-secret']);
        $responses[0]->rules()->create(['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'first', 'priority' => 0]);
        $responses[1]->update(['is_default' => true]);
        $before = $responses->map(fn ($response) => app(RevisionManager::class)->snapshot($response))->all();
        $summary = $this->import($this->document([$this->item()]), ImportMode::Upsert);
        self::assertSame(0, $endpoint->responses()->count());
        app(RevisionManager::class)->undoImport($summary->importBatchId);
        self::assertSame('rule', $endpoint->fresh()->selection_mode);
        self::assertSame($before, $endpoint->responses()->get()->map(fn ($response) => app(RevisionManager::class)->snapshot($response))->all());
        self::assertSame('restore-secret', $endpoint->responses()->first()->callback_signing_secret);
    }

    public function test_pool_undo_preserves_sequence_order_and_history_redacts_nested_signing_keys(): void
    {
        $this->import($this->document([$this->item(extra: ['response' => [['code' => 200, 'body' => 'z'], ['code' => 201, 'body' => 'b'], ['code' => 202, 'body' => 'a']]])]));
        $endpoint = MockEndpoint::query()->sole();
        $endpoint->update(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'loop']);
        foreach ($endpoint->responses as $index => $response) {
            $response->update(['sequence_order' => $index, 'callback_signing_secret' => 'private-key']);
        }
        $ciphertext = $endpoint->responses[0]->getRawOriginal('callback_signing_secret');
        $summary = $this->import($this->document([$this->item()]), ImportMode::Upsert);
        $revision = Revision::query()->where('import_batch_id', $summary->importBatchId)->sole();
        $diff = app(RevisionManager::class)->diffCurrent($revision);
        self::assertStringNotContainsString($ciphertext, json_encode($diff));
        self::assertStringContainsString('[redacted]', json_encode($diff));
        app(RevisionManager::class)->undoImport($summary->importBatchId);
        self::assertSame([0, 1, 2], $endpoint->responses()->get()->pluck('sequence_order')->all());
        self::assertSame('loop', $endpoint->fresh()->sequence_on_exhaust);
        self::assertSame([], app(RevisionManager::class)->diffCurrent($revision)['changes']);
    }

    public function test_masking_handles_quoted_credentials_and_json_key_order_is_not_stale(): void
    {
        $request = ['url' => '/users', 'header' => [['key' => 'Cookie', 'value' => "session=can\'t-leak"]], 'body' => ['mode' => 'raw', 'raw' => '{"a":1,"b":2}']];
        $json = $this->document([$this->item(extra: ['request' => $request, 'response' => [['code' => 200, 'originalRequest' => [...$request, 'url' => 'not-an-authoritative-url', 'body' => ['mode' => 'raw', 'raw' => '{"b":2,"a":1}']]]]])]);
        $mapped = app(PostmanMapper::class)->map($json, true)['items'][0];
        self::assertStringNotContainsString('leak', $mapped['raw_curl']);
        self::assertStringNotContainsString('stale', implode(' ', $mapped['warnings']));
    }

    public function test_nearest_event_list_and_explicit_noauth_override_collection_defaults(): void
    {
        $json = $this->document([['name' => 'Folder', 'event' => [['listen' => 'test', 'script' => ['exec' => ['folder-test']]]], 'item' => [$this->item(extra: ['request' => ['url' => '/users', 'auth' => ['type' => 'noauth']]])]]], ['auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => 'root-token']]], 'event' => [['listen' => 'prerequest', 'script' => ['exec' => ['root-script']]]]]);
        $item = app(PostmanMapper::class)->map($json)['items'][0];
        self::assertSame([], app(CurlParser::class)->parse($item['raw_curl'])->headers);
        self::assertStringContainsString('Test script', implode(' ', $item['warnings']));
        self::assertStringNotContainsString('Pre-request', implode(' ', $item['warnings']));
    }

    public function test_invalid_structure_and_legacy_formats_block_before_any_write(): void
    {
        foreach (['{"version":1,"requests":[]}', $this->document([['name' => 'No request or folder']]), '{broken'] as $json) {
            $plan = app(MappedImportService::class)->preview(app(PostmanMapper::class), $json, ImportMode::CreateOnly);
            self::assertFalse($plan->canApply());
            self::assertNotEmpty($plan->errors);
        }
        self::assertSame(0, MockEndpoint::query()->count());
    }
}
