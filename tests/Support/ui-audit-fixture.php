<?php

use App\Models\CallbackAttempt;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Config\ConfigExporter;
use App\Services\Environments\EnvironmentContext;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ViewErrorBag;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "Use a dedicated fixture database.\n");
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

MockEndpoint::query()->where('name', 'like', 'UI audit %')->get()->each->delete();
$context = app(EnvironmentContext::class);
$active = $context->active();
// Normalize only this isolated fixture; imports have a known unrelated default-model issue.
Environment::query()->update(['is_default' => false]);
Environment::query()->whereKey($active->id)->update(['is_default' => true]);
$active->refresh();
$active->variables()->updateOrCreate(['key' => 'UI_AUDIT'], ['value' => 'sample', 'is_secret' => false]);
$active->variables()->updateOrCreate(['key' => 'UI_AUDIT_SECRET'], ['value' => 'sample-secret', 'is_secret' => true]);
$collection = Collection::query()->firstOrCreate(['name' => 'UI audit collection']);
$tag = Tag::query()->firstOrCreate(['name' => 'UI audit tag']);
$endpoint = null;
foreach (range(1, 20) as $number) {
    $row = MockEndpoint::factory()->create([
        'name' => 'UI audit example '.$number,
        'raw_curl' => "curl 'https://api.example.test/ui-audit-{$number}'",
        'normalized_curl' => "GET\n/ui-audit-{$number}\n\n",
        'curl_hash' => hash('sha256', "GET\n/ui-audit-{$number}\n\n"),
        'collection_id' => $collection->id,
    ]);
    $row->tags()->sync([$tag->id]);
    if ($number === 1) {
        $endpoint = $row;
    }
}
$response = $endpoint->responses()->create([
    'body' => '{"ok":true}', 'callback_enabled' => true,
    'callback_url' => 'https://receiver.example.test/hook', 'callback_method' => 'POST',
]);
$endpoint->responses()->create(['status_code' => 201, 'body' => '{"second":true}']);
$revisions = app(RevisionManager::class);
foreach ([1, 2] as $version) {
    $snapshot = $revisions->snapshot($endpoint);
    $snapshot['name'] = 'UI audit previous title '.$version;
    $revisions->record($endpoint, $snapshot);
}
CallbackAttempt::query()->create([
    'response_id' => $response->id, 'request_log_id' => 'ui-audit-request',
    'target_url' => 'https://receiver.example.test/hook', 'method' => 'POST',
    'resolved_headers' => [], 'resolved_body' => '{}',
    'attempt_number' => 1, 'environment' => $active->name,
    'status' => 'success', 'http_status' => 204, 'duration_ms' => 18,
]);
$event = [
    'timestamp' => now()->toIso8601String(), 'request_id' => 'ui-audit-request',
    'method' => 'GET', 'url' => 'http://localhost/ui-audit-1', 'endpoint_id' => $endpoint->id,
    'match_tier' => 'hash', 'status_code' => 200, 'duration_ms' => 10,
    'environment' => $active->name, 'selection_mode' => 'weighted', 'selection_reason' => 'selected',
];
file_put_contents(storage_path('logs/mock-requests-ui-audit.log'), json_encode($event)."\n");
$app['view']->share('errors', new ViewErrorBag);
$shells = ['login' => view('auth.login')->render()];
foreach ([404, 419, 429, 500, 503] as $status) {
    $shells[(string) $status] = view('errors.'.$status)->render();
}
echo json_encode([
    'endpoint' => $endpoint->id, 'response' => $response->id,
    'import' => json_encode(app(ConfigExporter::class)->export([$endpoint->uuid])->data),
    'shells' => $shells,
], JSON_THROW_ON_ERROR);
