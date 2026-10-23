<?php

use App\Models\CallbackAttempt;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ImportMode;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Requires an explicitly disposable database and MOCKDECK_BROWSER_FIXTURE=1.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$environment = Environment::query()->where('is_default', true)->firstOrFail();
$other = Environment::query()->firstOrCreate(['name' => 'Regression other']);
$variable = $environment->variables()->updateOrCreate(['key' => 'UI_REGRESSION'], ['value' => 'original', 'is_secret' => false]);
$curl = "curl 'https://example.test/critique-".Str::uuid()."'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
$endpoint = MockEndpoint::factory()->create(['name' => 'Critique regression endpoint', 'raw_curl' => $curl,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash]);
$response = $endpoint->responses()->create(['body' => '{"regression":true}', 'external_label' => 'Distinct saved example',
    'callback_enabled' => true, 'callback_url' => 'https://current.example.test/hook']);
foreach ([1, 2] as $version) {
    $snapshot = app(RevisionManager::class)->snapshot($endpoint);
    $snapshot['name'] = 'Previous regression name '.$version;
    app(RevisionManager::class)->record($endpoint, $snapshot);
}
$attempt = CallbackAttempt::query()->create([
    'response_id' => $response->id, 'request_log_id' => 'critique-regression-request',
    'target_url' => 'https://recorded.example.test/hook?token=hidden-regression-secret', 'method' => 'POST',
    'resolved_headers' => [], 'resolved_body' => '{}', 'attempt_number' => 1,
    'environment' => $environment->name, 'status' => 'failed', 'http_status' => 500,
    'error' => 'Receiver unavailable: Bearer hidden-error-secret',
]);
$native = app(ConfigExporter::class)->export([$endpoint->uuid], redactSecrets: false)->data;
$native['endpoints'][0]['name'] = 'Prospective import name';
$postman = ['info' => ['name' => 'Critique preview', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
    'item' => [['name' => 'Imported example', 'request' => ['method' => 'GET', 'url' => 'https://example.test/critique-import',
        'header' => [['key' => 'Authorization', 'value' => 'Bearer {{regression_token}}']]],
        'response' => [['name' => 'Import trace label', 'code' => 200, 'body' => 'before']]]]];
$service = app(MappedImportService::class);
$plan = $service->preview(app(PostmanMapper::class), json_encode($postman), ImportMode::CreateOnly);
$service->apply($plan->token, $plan->digest);
$postman['item'][0]['response'][0]['body'] = 'after';

echo json_encode(['endpoint' => $endpoint->id, 'response' => $response->id, 'environment' => $environment->id,
    'other' => $other->id, 'variable' => $variable->id, 'attempt' => $attempt->id,
    'native' => json_encode($native), 'postman' => json_encode($postman)], JSON_THROW_ON_ERROR);
