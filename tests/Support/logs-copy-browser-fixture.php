<?php

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Contracts\Console\Kernel;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    throw new RuntimeException('Use a dedicated fixture database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$raw = "curl --request GET 'https://api.example.test/b01/hello'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, false, true);
$endpoint = MockEndpoint::factory()->create([
    'name' => 'Logs copy browser fixture', 'method' => 'GET', 'raw_curl' => $raw,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
    'exclude_headers' => true, 'enabled' => true,
]);
$endpoint->responses()->create(['status_code' => 200, 'body' => '{"ok":true}']);

echo json_encode(['endpoint' => $endpoint->id], JSON_THROW_ON_ERROR);
