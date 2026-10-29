<?php

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Requires an explicitly disposable database.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = '/selection-workspace-'.Str::uuid();
$curl = "curl 'https://source.example.test".$path."'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
$endpoint = MockEndpoint::factory()->create(['name' => 'Selection workspace', 'raw_curl' => $curl,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'exclude_headers' => true]);
$ids = [];
foreach ([503, 503, 200] as $index => $status) {
    $ids[] = $endpoint->responses()->create(['status_code' => $status, 'body' => json_encode(['step' => $index + 1]),
        'headers' => ['Content-Type' => 'application/json'], 'weight' => $index === 1 ? 2 : 1,
        'external_label' => ['First retry', 'Second retry', 'Success fallback'][$index]])->id;
}
$active = app(EnvironmentContext::class)->active();
$other = Environment::create(['name' => 'Other sequence '.Str::uuid(), 'is_default' => false]);
EndpointCallState::create(['endpoint_id' => $endpoint->id, 'environment_id' => $other->id, 'sequence_position' => 2, 'total_match_count' => 7]);
echo json_encode(['endpoint' => $endpoint->id, 'responses' => $ids, 'path' => $path, 'active' => $active->id,
    'environment' => $active->name, 'other' => $other->id], JSON_THROW_ON_ERROR);
