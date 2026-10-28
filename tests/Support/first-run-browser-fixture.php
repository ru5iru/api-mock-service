<?php

use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Contracts\Console\Kernel;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    throw new RuntimeException('Use a dedicated fixture database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$service = app(MappedImportService::class);
$document = json_encode(['info' => ['name' => 'Q03', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
    'item' => [['name' => 'Q03 zero-example import', 'request' => ['method' => 'GET', 'url' => 'https://example.test/q03/imported'], 'response' => []]],
], JSON_THROW_ON_ERROR);
$plan = $service->preview(app(PostmanMapper::class), $document, ImportMode::CreateOnly);
if ($plan->errors !== []) {
    throw new RuntimeException(json_encode($plan->errors, JSON_THROW_ON_ERROR));
}
$service->apply($plan->token, $plan->digest);
$imported = MockEndpoint::query()->where('name', 'Q03 zero-example import')->sole();
$raw = "curl 'https://example.test/q03/cookies'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, true, false);
$cookie = MockEndpoint::factory()->create(['name' => 'Q03 cookie candidate', 'method' => 'GET', 'raw_curl' => $raw,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'exclude_headers' => false, 'exclude_cookies' => false, 'exclude_auth' => true]);
echo json_encode(['imported' => $imported->id, 'cookie' => $cookie->id], JSON_THROW_ON_ERROR);
