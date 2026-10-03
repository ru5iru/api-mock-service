<?php

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Environments\EnvironmentContext;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\Console\Kernel;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Use a dedicated database and set MOCKDECK_BROWSER_FIXTURE=1.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

MockEndpoint::query()->where('raw_curl', 'like', '%/dialog-browser-%')->get()->each->delete();
Environment::query()->where('name', 'Dialog browser environment')->get()->each->delete();
$context = app(EnvironmentContext::class);
if (Environment::query()->where('is_default', true)->count() !== 1) {
    $context->makeDefault($context->active());
}
$environment = Environment::query()->create(['name' => 'Dialog browser environment', 'is_default' => false]);
$variable = $environment->variables()->create(['key' => 'DIALOG_TEST', 'value' => 'sample', 'is_secret' => false]);
$endpoints = [];
foreach (['main', 'single', 'bulk-a', 'bulk-b'] as $key) {
    $curl = "curl 'https://api.example.test/dialog-browser-{$key}'";
    $variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($curl), false, false, true);
    $endpoint = MockEndpoint::factory()->create([
        'name' => 'Dialog browser '.$key, 'raw_curl' => $curl,
        'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
    ]);
    $endpoint->responses()->create(['body' => '{"ok":true}', 'sequence_order' => 0]);
    $endpoints[$key] = $endpoint;
}
$main = $endpoints['main'];
$main->responses()->create(['status_code' => 201, 'body' => '{"ok":"second"}', 'sequence_order' => 1]);
$main->update(['selection_mode' => 'sequence', 'sequence_on_exhaust' => 'repeat_last']);
EndpointCallState::query()->create([
    'endpoint_id' => $main->id, 'environment_id' => $context->active()->id,
    'sequence_position' => 1, 'total_match_count' => 2,
]);
$revisions = app(RevisionManager::class);
$revisions->record($main, $revisions->snapshot($main));
$main->update(['priority' => 9]);
$revisions->record($main, $revisions->snapshot($main));
$document = app(ConfigExporter::class)->export([$main->uuid])->data;
$document['endpoints'][0]['name'] = 'Dialog browser imported';
echo json_encode([
    'main' => $main->id, 'single' => $endpoints['single']->id,
    'bulk' => [$endpoints['bulk-a']->id, $endpoints['bulk-b']->id],
    'environment' => $environment->id, 'variable' => $variable->id,
    'import' => json_encode($document, JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);
