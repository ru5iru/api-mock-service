<?php

namespace Tests\Feature;

use App\Jobs\DeliverCallback;
use App\Livewire\Admin\CallbackLog;
use App\Livewire\Admin\ConfigTransfer;
use App\Livewire\Admin\EndpointForm;
use App\Livewire\Admin\RevisionHistory;
use App\Logging\FlatJsonFormatter;
use App\Models\ApiToken;
use App\Models\McpUnmatchedRequest;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Logging\LogTailer;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDispatcher;
use App\Services\TestRuns\RunRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\SensitiveDataFixture;
use Tests\TestCase;

final class SensitiveDataAuditTest extends TestCase
{
    use RefreshDatabase;

    private array $captures = [];

    protected function beforeRefreshingDatabase(): void
    {
        if ($expected = getenv('AUDIT_DB')) {
            self::assertSame('pgsql', DB::getDriverName());
            self::assertSame($expected, DB::selectOne('select current_database() as name')->name);
        }
    }

    private function capture(string $location, mixed $output): string
    {
        $count = $this->captures[$location] = ($this->captures[$location] ?? 0) + 1;
        $location .= $count > 1 ? '-'.$count : '';
        $text = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($directory = getenv('AUDIT_OUTPUT')) {
            file_put_contents($directory.'/'.$location.'.txt', $text);
            $rows = [];
            foreach (SensitiveDataFixture::canaries() as $name => $canary) {
                $rows[] = ['location' => $location, 'canary' => $name, 'found' => str_contains($text, $canary)];
            }
            file_put_contents($directory.'/matrix.jsonl', implode("\n", array_map(json_encode(...), $rows))."\n", FILE_APPEND);
        }

        return $text;
    }

    private function absent(string $text, array $names): void
    {
        foreach ($names as $name) {
            self::assertStringNotContainsString(SensitiveDataFixture::canaries()[$name], $text, $name);
        }
    }

    public function test_documented_storage_export_api_and_history_guarantees(): void
    {
        $f = SensitiveDataFixture::create();
        $c = SensitiveDataFixture::canaries();
        foreach (DB::table('environment_variables')->get() as $row) {
            $this->absent($row->value, ['ENV-SECRET', 'ENV-PLAIN']);
            self::assertContains(Crypt::decryptString($row->value), [$c['ENV-SECRET'], $c['ENV-PLAIN']]);
        }
        $this->capture('sql-environment-values', DB::table('environment_variables')->get());
        $this->capture('sql-response', DB::table('mock_responses')->get());
        $this->capture('sql-endpoint', DB::table('mock_endpoints')->get());
        $this->absent($this->capture('sql-revisions', DB::table('revisions')->get()), ['SIGN']);
        $stored = DB::table('mock_responses')->where('id', $f['response']->id)->value('callback_signing_secret');
        $this->absent($stored, ['SIGN']);
        self::assertSame($c['SIGN'].'-changed', Crypt::decryptString($stored));
        $this->absent($this->capture('sql-callback-attempts', DB::table('callback_attempts')->get()), ['CBURL', 'CBBODY', 'HDR-AUTH', 'HDR-COOKIE']);
        $attempt = DB::table('callback_attempts')->where('id', $f['attempt']->id)->first();
        self::assertSame($f['response']->callback_url, Crypt::decryptString($attempt->target_url));
        self::assertSame($c['CBBODY'], Crypt::decryptString($attempt->resolved_body));
        self::assertSame($f['headers'], json_decode(Crypt::decryptString($attempt->resolved_headers), true));
        $this->absent($this->capture('environment-get', $this->getJson('/api/environments/'.$f['environment']->id.'/variables')->assertOk()->getContent()), ['ENV-SECRET']);
        $this->absent($this->capture('environment-duplicate', $this->postJson('/api/environments/'.$f['environment']->id.'/duplicate', ['name' => 'S01 copy'])->assertSuccessful()->getContent()), ['ENV-SECRET']);
        $preview = $this->postJson('/api/response-templates/preview', ['template' => '{"value":"{{env.API_TOKEN}}"}', 'endpoint_id' => $f['endpoint']->id])->assertUnprocessable();
        $preview->assertJsonPath('issues.0.code', 'SECRET_PREVIEW_HIDDEN');
        $this->absent($this->capture('environment-secret-preview', $preview->getContent()), ['ENV-SECRET']);
        foreach ([true, false] as $redact) {
            $document = app(ConfigExporter::class)->export(redactSecrets: $redact)->data;
            $text = $this->capture('export-'.($redact ? 'redacted' : 'sensitive'), $document);
            $this->absent($text, ['SIGN', 'ENV-SECRET']);
            self::assertNull($document['endpoints'][0]['responses'][0]['callback']['signing_secret']);
            if ($redact) {
                $this->absent($text, ['HDR-AUTH', 'HDR-PROXY', 'HDR-COOKIE', 'HDR-APIKEY', 'QRY-TOKEN', 'CBURL', 'CBHDR']);
            }
            $this->absent($this->capture('native-preview-'.(int) $redact, app(ConfigImporter::class)->preview(json_encode($document), ImportMode::Upsert)->toArray()), ['SIGN', 'ENV-SECRET']);
        }
        $this->absent($this->capture('dashboard-export', $this->postJson('/dashboard/config/exports', ['redact_secrets' => true])->assertSuccessful()->getContent()), ['SIGN', 'ENV-SECRET', 'HDR-AUTH', 'QRY-TOKEN']);
        foreach ([[], ['--include-sensitive' => true]] as $options) {
            self::assertSame(0, Artisan::call('mockdeck:export', $options));
            $this->absent($this->capture('cli-export-'.($options === [] ? 'redacted' : 'sensitive'), Artisan::output()), ['SIGN', 'ENV-SECRET']);
        }
        $callbackApi = $this->getJson('/api/callback-attempts')->assertOk();
        foreach (['resolved_headers', 'resolved_body', 'target_url', 'callback_signing_secret'] as $field) {
            $callbackApi->assertJsonMissingPath('data.0.'.$field);
        }
        $this->absent($this->capture('callback-api', $callbackApi->getContent()), ['SIGN', 'HDR-AUTH', 'CBBODY']);
        $this->absent($this->capture('callback-dom', Livewire::test(CallbackLog::class)->html()), ['SIGN', 'CBURL', 'HDR-AUTH', 'CBBODY']);
        foreach (['endpoints' => $f['endpoint'], 'responses' => $f['response']] as $type => $entity) {
            $this->absent($this->capture($type.'-history-api', $this->getJson('/api/'.$type.'/'.$entity->id.'/revisions')->assertOk()->getContent()), ['SIGN']);
        }
        $this->absent($this->capture('response-diff-api', $this->getJson('/api/responses/'.$f['response']->id.'/revisions/'.$f['revision']->id.'/diff/current')->assertOk()->getContent()), ['SIGN']);
        $endpointRevision = $f['endpoint']->revisions()->oldest('version_number')->firstOrFail();
        $f['endpoint']->update(['name' => 'S01 changed '.$c['CURLURL']]);
        $this->capture('endpoint-diff-api', $this->getJson('/api/endpoints/'.$f['endpoint']->id.'/revisions/'.$endpointRevision->id.'/diff/current')->assertOk()->getContent());
        $this->absent($this->capture('history-dialog', Livewire::test(RevisionHistory::class, ['entityType' => 'response', 'entityId' => $f['response']->id])->call('compareWithCurrent', $f['revision']->id)->html()), ['SIGN']);
        $this->capture('registry-dom', $this->get('/dashboard')->assertOk()->getContent());
        $this->absent($this->capture('editor-dom', $this->get('/dashboard/endpoints/'.$f['endpoint']->id.'/edit')->assertOk()->getContent()), ['SIGN', 'ENV-SECRET']);
        $this->capture('validation-dom', Livewire::test(EndpointForm::class)->set('rawCurl', "curl 'ftp://localhost/invalid?token=".$c['QRY-TOKEN']."'")->call('save')->assertHasErrors('rawCurl')->html());
        DeliverCallback::dispatch($f['response']->id, 's01-job', $f['environment']->id, ['body' => $c['BODY-JSON'], 'headers' => $f['headers']]);
        $job = DB::table('jobs')->sole();
        $this->absent($this->capture('jobs', json_decode($job->payload, true)), ['BODY-JSON', 'HDR-AUTH', 'SIGN']);
        $command = json_decode($job->payload, true)['data']['command'];
        self::assertStringContainsString($c['BODY-JSON'], Crypt::decryptString($command));
    }

    public function test_mcp_result_cache_audit_encrypted_evidence_and_run_history_guarantees(): void
    {
        $f = SensitiveDataFixture::create();
        $c = SensitiveDataFixture::canaries();
        $owner = app(McpCredentialService::class)->generate('S01 audit', ['read', 'write', 'verify', 'cleanup'])->credential;
        $outputs = [];
        $call = function ($tool, $args) use ($owner, &$outputs) {
            $result = app(McpDispatcher::class)->call($tool, $args, $owner);
            $this->capture('mcp-'.$tool, $result);
            self::assertTrue($result['ok'], json_encode($result));
            $outputs[] = $result;

            return $result;
        };
        $scope = ['run_id' => $call('start_test_run', ['label' => 'S01 canary run'])['data']['run_id']];
        $configuration = ['curl' => $f['curl'], 'matching' => ['exclude_headers' => true], 'responses' => [['body' => json_encode(['password' => $c['BODY-JSON'], 'context' => $c['ENV-SECRET'], 'literal' => $c['TEMPLATE']])]]];
        $create = [...$scope, 'idempotency_key' => 's01-canary', 'configuration' => $configuration];
        $created = $call('create_mock', $create);
        $call('create_mock', $create);
        $mock = [...$scope, 'mock_id' => $created['data']['mock_id']];
        $call('get_mock', $mock);
        $call('update_mock', [...$mock, 'expected_version' => 1, 'changes' => ['endpoint' => ['name' => 'S01 changed']]]);
        $this->call('POST', str_replace('http://localhost', '', $f['url']), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MOCKDECK_TEST_RUN' => $scope['run_id'], 'HTTP_AUTHORIZATION' => $f['headers']['Authorization'], 'HTTP_COOKIE' => $f['headers']['Cookie'], 'HTTP_X_API_KEY' => $c['HDR-APIKEY'], 'HTTP_X_INTERNAL_TOKEN' => $c['HDR-INTERNAL'], 'HTTP_PROXY_AUTHORIZATION' => $f['headers']['Proxy-Authorization']], $f['body'])->assertOk();
        $this->get('/audit/unmatched?token='.$c['QRY-TOKEN'].'&ref='.$c['QRY-PLAIN'], [RunRouting::HEADER => $scope['run_id'], ...$f['headers']])->assertNotFound();
        $call('get_calls', $mock);
        $logs = $call('get_unmatched_requests', $scope);
        self::assertNotEmpty($logs['data']['items']);
        $call('explain_match', [...$scope, 'log_entry_id' => $logs['data']['items'][0]['id']]);
        $call('assert_calls', [...$mock, 'expect_count' => 1, 'comparator' => 'equals']);
        $call('preview_template', [...$scope, 'template' => json_encode(['password' => $c['BODY-JSON'], 'literal' => $c['TEMPLATE']])]);
        $form = $call('create_mock', [...$scope, 'idempotency_key' => 's01-form', 'configuration' => ['curl' => "curl http://localhost/audit/mcp-form --data 'password=".$c['BODY-FORM']."'", 'responses' => [['body' => 'ok']]]]);
        $this->capture('mcp-form-output', $form);
        $this->capture('mcp-history-page', $this->get('/dashboard/test-runs/'.$scope['run_id'].'/history')->assertOk()->getContent());
        $this->absent($this->capture('mcp-audit-rows', DB::table('mcp_invocations')->get()), array_keys($c));
        $outputs[] = DB::table('mcp_idempotency_keys')->get();
        $this->capture('mcp-cache-rows', end($outputs));
        $this->absent($this->capture('mcp-evidence-ciphertext', DB::table('mcp_unmatched_requests')->get()), array_keys($c));
        self::assertStringContainsString($c['QRY-TOKEN'], json_encode(McpUnmatchedRequest::query()->latest('sequence')->firstOrFail()->request));
        $outputs[] = DB::table('revisions')->whereNotNull('test_run_id')->get();
        $this->capture('mcp-revisions', end($outputs));
        $this->capture('sql-mcp-endpoints', DB::table('mock_endpoints')->whereNotNull('test_run_id')->get());
        $this->capture('sql-mcp-responses', DB::table('mock_responses')->whereIn('mock_endpoint_id', DB::table('mock_endpoints')->whereNotNull('test_run_id')->select('id'))->get());
        foreach ($outputs as $output) {
            $this->absent(json_encode($output), ['HDR-AUTH', 'HDR-PROXY', 'HDR-COOKIE', 'HDR-APIKEY', 'HDR-INTERNAL', 'QRY-TOKEN', 'BODY-JSON', 'BODY-FORM', 'SIGN', 'ENV-SECRET']);
        }
    }

    public function test_postman_preview_hides_secret_variable_values_and_masked_literal_auth(): void
    {
        $c = SensitiveDataFixture::canaries();
        $collection = ['info' => ['name' => 'S01', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'], 'variable' => [['key' => 'API_TOKEN', 'value' => $c['ENV-SECRET']]], 'item' => [['name' => 'S01 literal', 'request' => ['method' => 'GET', 'url' => 'http://localhost/postman', 'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => $c['HDR-AUTH'], 'type' => 'string']]]]], ['name' => 'S01 variable', 'request' => ['method' => 'GET', 'url' => 'http://localhost/postman-variable', 'header' => [['key' => 'Authorization', 'value' => 'Bearer {{API_TOKEN}}']]]]]];
        foreach ([false, true] as $mask) {
            $plan = app(MappedImportService::class)->preview(app(PostmanMapper::class), json_encode($collection), ImportMode::CreateOnly, $mask);
            self::assertSame([], $plan->errors);
            $text = $this->capture('postman-preview-'.(int) $mask, $plan->toArray());
            $this->absent($text, ['ENV-SECRET']);
            if ($mask) {
                $this->absent($text, ['HDR-AUTH']);
            }
        }
        $this->capture('import-idle-dom', Livewire::test(ConfigTransfer::class)->html());
    }

    public function test_primary_log_query_masking_leaves_serving_and_signatures_unchanged(): void
    {
        $f = SensitiveDataFixture::create();
        $c = SensitiveDataFixture::canaries();
        $signature = $f['endpoint']->only(['raw_curl', 'normalized_curl', 'curl_hash', 'signature_version']);
        // CI disables request logging. Exercise the real logger/tailer with a
        // private file, without enabling or reading the instance's log files.
        $storage = storage_path();
        $channel = config('logging.channels.mock_requests');
        $temporary = sys_get_temp_dir().'/mockdeck-log-audit-'.Str::uuid();
        File::ensureDirectoryExists($temporary.'/logs');
        $this->app->useStoragePath($temporary);
        config(['logging.channels.mock_requests' => [
            'driver' => 'single',
            'path' => $temporary.'/logs/mock-requests.log',
            'formatter' => FlatJsonFormatter::class,
        ]]);
        Log::forgetChannel('mock_requests');
        try {
            $request = $this->call('POST', str_replace('http://localhost', '', $f['url']), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $f['headers']['Authorization'], 'HTTP_X_INTERNAL_TOKEN' => $c['HDR-INTERNAL'], 'HTTP_COOKIE' => $f['headers']['Cookie']], $f['body'])->assertOk()->assertContent($c['TEMPLATE']);
            $missing = $this->get('/audit/missing?token='.$c['QRY-TOKEN'].'&ref='.$c['QRY-PLAIN'])->assertNotFound();
            foreach ([$request, $missing] as $response) {
                $event = collect(app(LogTailer::class)->recent(100))->firstWhere('request_id', $response->headers->get('X-Request-ID'));
                self::assertNotNull($event);
                $text = $this->capture('request-file-'.($event['matched'] ? 'matched' : 'unmatched'), $event);
                $this->absent($text, ['QRY-TOKEN', 'HDR-AUTH', 'BODY-JSON']);
                self::assertStringContainsString('token=[REDACTED]', $event['url']);
                self::assertStringContainsString('ref='.$c['QRY-PLAIN'], $event['url']);
            }
        } finally {
            Log::channel('mock_requests')->getLogger()->close();
            Log::forgetChannel('mock_requests');
            config(['logging.channels.mock_requests' => $channel]);
            $this->app->useStoragePath($storage);
            File::deleteDirectory($temporary);
        }
        self::assertSame($signature, $f['endpoint']->fresh()->only(array_keys($signature)));
        $token = 'mdv_'.str_repeat('b', 64);
        ApiToken::query()->create(['name' => 'S01 verification', 'token_hash' => hash('sha256', $token)]);
        $calls = $this->getJson('/api/v1/verify/endpoints/'.$f['endpoint']->uuid.'/calls?environment=Development', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('total_match_count', 1);
        $this->absent($this->capture('verification-api', $calls->getContent()), ['HDR-AUTH', 'HDR-INTERNAL', 'HDR-COOKIE', 'QRY-TOKEN', 'BODY-JSON']);
        $this->capture('verification-sql', DB::table('endpoint_call_state')->get());
        self::assertSame(hash('sha256', $c['QRY-TOKEN']), $calls->json('recent_calls.0.query_digest.fields.token.sha256'));
        self::assertSame($c['QRY-PLAIN'], $calls->json('recent_calls.0.query_digest.fields.ref.value'));
    }
}
