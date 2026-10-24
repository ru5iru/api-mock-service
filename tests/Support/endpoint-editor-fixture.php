<?php

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Requires a disposable database and MOCKDECK_BROWSER_FIXTURE=1.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$curl = "curl 'https://example.test/endpoint-editor-".Str::uuid()."/123'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
$endpoint = MockEndpoint::factory()->create(['name' => 'Endpoint editor regression', 'raw_curl' => $curl,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'exclude_headers' => true]);
$ids = [];
foreach (['first', 'second', 'third'] as $label) {
    $ids[] = $endpoint->responses()->create(['body' => '{"label":"'.$label.'"}', 'external_label' => $label,
        'callback_enabled' => true, 'callback_url' => 'https://saved.example.test/hook'])->id;
}
echo json_encode(['endpoint' => $endpoint->id, 'responses' => $ids], JSON_THROW_ON_ERROR);
