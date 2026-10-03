<?php

use App\Models\MockEndpoint;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Contracts\Console\Kernel;

// Invoke only against a dedicated smoke-test database, never an operator database.
if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Set MOCKDECK_BROWSER_FIXTURE=1 and use a dedicated database.\n");
    exit(1);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

MockEndpoint::query()->where('name', 'Wave 1 browser fixture')
    ->orWhere('raw_curl', "curl 'https://api.example.test/wave1-browser'")
    ->get()->each->delete();
$endpoint = MockEndpoint::factory()->create([
    'name' => 'Wave 1 browser fixture',
    'raw_curl' => "curl 'https://api.example.test/wave1-browser'",
    'normalized_curl' => "GET\n/wave1-browser\n\n",
    'curl_hash' => hash('sha256', "GET\n/wave1-browser\n\n"),
]);
$a = $endpoint->responses()->create([
    'status_code' => 200, 'headers' => ['Content-Type' => 'application/json'],
    'body' => '{"response":"first"}', 'weight' => 1, 'delay_ms' => 0,
]);
$b = $endpoint->responses()->create([
    'status_code' => 201, 'headers' => ['Content-Type' => 'application/json'],
    'body' => '{"response":"second"}', 'weight' => 1, 'delay_ms' => 0,
]);
$environment = app(EnvironmentContext::class)->active();
$environment->variables()->updateOrCreate(['key' => 'NAME'], ['value' => 'Wave One sample', 'is_secret' => false]);
echo json_encode(['endpoint' => $endpoint->id, 'first' => $a->id, 'second' => $b->id], JSON_THROW_ON_ERROR);
