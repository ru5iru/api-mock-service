<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Services\Mcp\McpCredentialService;
use App\Services\Templates\TemplateContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual official MCP client over TCP. No Http::fake or in-process server dispatch. */
final class McpClientIntegrationTest extends TestCase
{
    public function test_official_client_discovers_calls_and_handles_structured_errors_over_http_and_trusted_https(): void
    {
        $original = config('database.connections.sqlite');
        $default = config('database.default');
        $database = tempnam(sys_get_temp_dir(), 'mockdeck-mcp-client-');
        $server = $tls = null;
        $cert = $database.'.pem';
        $key = $database.'.key';
        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $issued = app(McpCredentialService::class)->generate('official client', ['read', 'write', 'verify', 'cleanup']);
            $token = $issued->reveal();
            self::assertNotNull(Environment::query()->first());
            $port = $this->port();
            $tlsPort = $this->port();
            $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', public_path(), public_path('index.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://127.0.0.1:'.$port, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MOCK_DASHBOARD_AUTH_ENABLED' => 'false', 'MOCK_LOG_STDOUT' => 'false', 'MOCK_LOG_FILE' => 'false']);
            $server->start();
            $this->ready('http://127.0.0.1:'.$port.'/up', $server);
            $configFile = $database.'.cnf';
            file_put_contents($configFile, "[req]\ndistinguished_name=dn\n[dn]\n[v3_req]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\n");
            $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $configFile]);
            $csr = openssl_csr_new(['commonName' => 'localhost'], $privateKey, ['config' => $configFile]);
            $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['config' => $configFile, 'x509_extensions' => 'v3_req']);
            openssl_x509_export_to_file($certificate, $cert);
            openssl_pkey_export_to_file($privateKey, $key, null, ['config' => $configFile]);
            $tls = new Process([PHP_BINARY, base_path('tests/Support/mcp-tls-proxy.php'), (string) $tlsPort, (string) $port, $cert, $key]);
            $tls->start();
            // Pin our generated CA explicitly. No verification bypass is used.
            Http::globalOptions(['verify' => $cert]);
            $this->ready('https://localhost:'.$tlsPort.'/up', $tls);
            foreach (['http://127.0.0.1:'.$port, 'https://localhost:'.$tlsPort] as $origin) {
                $client = Client::web($origin.'/mcp')->withToken($token)->withProtocolVersion(ProtocolVersion::V2025_11_25);
                self::assertCount(18, $client->tools());
                self::assertSame('2025-11-25', $client->initializeResult()->protocolVersion);
                $invoke = function (string $name, array $args) use ($client): array {
                    $result = $client->callTool($name, $args);
                    self::assertFalse($result->isError, $result->text());
                    self::assertTrue($result->structuredContent['ok']);

                    return $result->structuredContent['data'];
                };
                $run = ['run_id' => $invoke('start_test_run', ['label' => 'real client'])['run_id']];
                $mock = $invoke('create_mock', [...$run, 'idempotency_key' => 'real-client', 'configuration' => ['request' => ['url' => 'http://localhost/client'], 'responses' => [['body' => '{"client":true}']]]]);
                Http::get($origin.parse_url($mock['invocation']['path_prefix']['url'], PHP_URL_PATH))->throw();
                Http::withHeaders($mock['invocation']['header']['headers'])->get($origin.parse_url($mock['invocation']['header']['url'], PHP_URL_PATH))->throw();
                $target = [...$run, 'mock_id' => $mock['mock_id']];
                self::assertSame(2, $invoke('get_calls', $target)['total_match_count']);
                self::assertTrue($invoke('assert_calls', [...$target, 'expect_count' => 2, 'comparator' => 'equals'])['passed']);
                $failed = $client->callTool('assert_calls', [...$target, 'expect_count' => 9, 'comparator' => 'equals']);
                self::assertTrue($failed->isError);
                self::assertSame('ASSERTION_FAILED', $failed->structuredContent['error']['code']);
                $faultChanges = ['responses' => ['update' => [['id' => $mock['responses'][0]['id'], 'fault_enabled' => true, 'fault_type' => 'delay',
                    'fault_delay_ms_min' => 25, 'fault_delay_ms_max' => 25, 'fault_probability' => 100]]]];
                self::assertTrue($invoke('validate_mock', [...$target, 'changes' => $faultChanges])['valid']);
                $faulted = $invoke('update_mock', [...$target, 'expected_version' => 1, 'changes' => $faultChanges]);
                self::assertTrue($faulted['responses'][0]['fault_enabled']);
                self::assertSame('delay', $faulted['responses'][0]['fault_type']);
                $started = hrtime(true);
                Http::withHeaders($mock['invocation']['header']['headers'])->get($origin.'/client')->throw();
                self::assertGreaterThanOrEqual(24, (hrtime(true) - $started) / 1_000_000);
                self::assertSame(3, $invoke('get_calls', $target)['total_match_count']);
                $unsupported = $client->callTool('validate_mock', [...$target, 'changes' => ['responses' => ['update' => [['id' => $mock['responses'][0]['id'], 'fault_type' => 'connection_reset']]]]]);
                self::assertTrue($unsupported->isError);
                self::assertSame('VALIDATION_ERROR', $unsupported->structuredContent['error']['code']);
                $sequence = $invoke('create_mock', [...$run, 'idempotency_key' => 'sequence-client', 'configuration' => [
                    'request' => ['url' => 'http://localhost/sequence-client'], 'matching' => ['exclude_headers' => true],
                    'selection_mode' => 'sequence', 'sequence_on_exhaust' => 'repeat_last',
                    'responses' => [['status' => 503, 'sequence_order' => 0], ['status' => 503, 'sequence_order' => 1], ['status' => 200, 'sequence_order' => 2]],
                ]]);
                $selectionTarget = [...$run, 'mock_id' => $sequence['mock_id']];
                foreach ([503, 503, 200] as $index => $status) {
                    $peek = $invoke('peek_next_response', $selectionTarget);
                    self::assertSame(($index + 1).' of 3', $peek['position_label']);
                    self::assertSame($status, $peek['response']['status']);
                    $served = $index % 2 === 0
                        ? Http::withHeaders($sequence['invocation']['header']['headers'])->get($origin.'/sequence-client')
                        : Http::get($origin.parse_url($sequence['invocation']['path_prefix']['url'], PHP_URL_PATH));
                    self::assertSame($status, $served->status());
                }
                $changes = ['endpoint' => ['selection_mode' => 'rule'], 'responses' => [
                    'remove' => [$sequence['responses'][0]['id'], $sequence['responses'][1]['id']],
                    'update' => [['id' => $sequence['responses'][2]['id'], 'is_default' => true]],
                    'add' => [['status' => 201, 'rule_conditions' => [['field_type' => 'header', 'field_name' => 'X-Mode', 'operator' => 'equals', 'value' => 'yes', 'priority' => 0]]]],
                ]];
                self::assertTrue($invoke('validate_mock', [...$selectionTarget, 'changes' => $changes])['valid']);
                self::assertSame('rule', $invoke('update_mock', [...$selectionTarget, 'expected_version' => 1, 'changes' => $changes])['selection_mode']);
                self::assertSame(201, Http::withHeaders([...$sequence['invocation']['header']['headers'], 'X-Mode' => 'yes'])->get($origin.'/sequence-client')->status());
                self::assertSame(200, Http::withHeaders($sequence['invocation']['header']['headers'])->get($origin.'/sequence-client')->status());
                self::assertFalse($invoke('peek_next_response', $selectionTarget)['applicable']);
                $previewMock = $invoke('create_mock', [...$run, 'idempotency_key' => 'preview-client', 'configuration' => ['request' => ['url' => 'http://localhost/preview-client'],
                    'responses' => [['body_mode' => 'template', 'body' => '{"id":"$number.int({\"min\":7,\"max\":7})","name":"$person.fullName"}']]]]);
                $issued->credential->update(['can_write' => false]);
                $sample = $invoke('preview_template', [...$run, 'mock_id' => $previewMock['mock_id'], 'response_id' => $previewMock['responses'][0]['id'], 'seed' => 42]);
                self::assertSame(7, $sample['output']['id']);
                self::assertSame(TemplateContext::PREVIEW_NOTICE, $sample['sample_notice']);
                $rawSample = $invoke('preview_template', [...$run, 'template' => '{"id":"$string.uuid"}', 'seed' => 'random']);
                self::assertTrue(Str::isUuid($rawSample['output']['id']));
                $badTemplate = $client->callTool('preview_template', [...$run, 'template' => '{"bad":"$person.fristName"}']);
                self::assertTrue($badTemplate->isError);
                self::assertSame('UNKNOWN_METHOD', $badTemplate->structuredContent['error']['details']['issues'][0]['code']);
                $issued->credential->update(['can_write' => true]);
                $collection = json_encode(['info' => ['name' => 'Client import', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
                    'item' => [['name' => 'Imported', 'request' => ['method' => 'GET', 'url' => 'https://ignored.example/imported-client'],
                        'response' => [['name' => 'Success', 'code' => 200, 'body' => '{"imported":true}']]]]]);
                $importArgs = [...$run, 'collection' => $collection];
                $issued->credential->update(['can_write' => false]);
                $plan = $invoke('preview_postman_import', $importArgs);
                self::assertTrue($plan['can_apply']);
                self::assertSame('create', $plan['items'][0]['action']);
                $issued->credential->update(['can_write' => true]);
                $imported = $invoke('apply_postman_import', [...$importArgs, 'idempotency_key' => 'client-import']);
                self::assertSame($imported, $invoke('apply_postman_import', [...$importArgs, 'idempotency_key' => 'client-import']));
                $conflicting = $client->callTool('apply_postman_import', [...$importArgs, 'idempotency_key' => 'client-import', 'mode' => 'clone']);
                self::assertTrue($conflicting->isError);
                self::assertSame('IDEMPOTENCY_CONFLICT', $conflicting->structuredContent['error']['code']);
                $importMock = $imported['items'][0];
                Http::withHeaders($importMock['invocation']['header']['headers'])->get($origin.'/imported-client')->throw();
                Http::get($origin.parse_url($importMock['invocation']['path_prefix']['url'], PHP_URL_PATH))->throw();
                self::assertSame(2, $invoke('get_calls', [...$run, 'mock_id' => $importMock['mock_id']])['total_match_count']);
                self::assertSame('finished', $invoke('finish_test_run', [...$run, 'cleanup' => 'delete_mocks'])['status']);
                $client->disconnect();
            }
            $example = new Process([PHP_BINARY, base_path('scripts/mcp_example.php')], base_path(), ['MOCKDECK_MCP_URL' => 'http://127.0.0.1:'.$port.'/mcp', 'MOCKDECK_MCP_TOKEN' => $token]);
            $example->mustRun();
            self::assertSame(2, json_decode($example->getOutput(), true, flags: JSON_THROW_ON_ERROR)['total_match_count']);
            self::assertStringNotContainsString($token, $example->getOutput());
            $modern = Client::web('http://127.0.0.1:'.$port.'/mcp')->withToken($token)->withProtocolVersion(ProtocolVersion::V2026_07_28);
            self::assertCount(18, $modern->tools());
            self::assertNotNull($modern->discoverResult());
        } finally {
            $tls?->stop();
            $server?->stop();
            Http::globalOptions([]);
            DB::purge('sqlite');
            config(['database.default' => $default, 'database.connections.sqlite' => $original]);
            foreach ([$database, $cert, $key, $database.'.cnf'] as $file) {
                @unlink($file);
            }
        }
    }

    private function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function ready(string $url, Process $process): void
    {
        foreach (range(1, 80) as $_) {
            try {
                if (Http::timeout(1)->get($url)->successful()) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50000);
        }
        self::fail('Local server did not start: '.$process->getErrorOutput());
    }
}
