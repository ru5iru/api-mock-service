<?php

use App\Models\ApiToken;
use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Contracts\Console\Kernel;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Use a dedicated fixture database.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MockEndpoint::query()->where('name', 'Wave 2 browser fixture')->get()->each->delete();
$raw = "curl 'https://api.example.test/wave2-browser/users/42?noise=old&keep=1' -H 'X-Trace: old'";
$variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, true, false);
$endpoint = MockEndpoint::factory()->create(['name' => 'Wave 2 browser fixture', 'method' => 'GET', 'raw_curl' => $raw,
    'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash, 'exclude_auth' => true, 'exclude_headers' => false]);
$response = $endpoint->responses()->create(['status_code' => 200, 'headers' => ['Content-Type' => 'application/json'],
    'body_mode' => 'template', 'template' => '{"id":"$request.path.id"}', 'editor_view' => 'json', 'callback_body' => '{"id":"$request.path.id"}']);
$environment = app(EnvironmentContext::class)->active();
$token = 'mdv_'.str_repeat('b', 64);
ApiToken::query()->updateOrCreate(['name' => 'verification'], ['token_hash' => hash('sha256', $token), 'created_at' => now()]);
echo json_encode(['endpoint' => $endpoint->id, 'uuid' => $endpoint->uuid, 'response' => $response->id, 'environment' => $environment->name, 'token' => $token], JSON_THROW_ON_ERROR);
