<?php

use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1' || ! getenv('AUDIT_DB')) {
    throw new RuntimeException('Use a guarded disposable PostgreSQL database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql' || DB::selectOne('select current_database() as name')->name !== getenv('AUDIT_DB')) {
    throw new RuntimeException('Disposable database guard failed.');
}
$action = $argv[1] ?? '';
$label = $argv[2] ?? '';
if (! preg_match('/^[a-z0-9-]+$/D', $label)) {
    throw new RuntimeException('Invalid fixture label.');
}
$curl = "curl 'http://example.test/q01-save/".$label."/new'";
if ($action === 'create') {
    $endpoint = app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: "curl 'http://example.test/q01-save/".$label."/old'"));
    $a = $endpoint->responses()->create(['body' => 'before-a']);
    $b = $endpoint->responses()->create(['body' => 'before-b']);
    echo json_encode(['endpoint' => $endpoint->id, 'first' => $a->id, 'second' => $b->id, 'curl' => $curl]);
} elseif ($action === 'duplicate') {
    $endpoint = app(EndpointMutationService::class)->save(new EndpointInput(rawCurl: $curl));
    echo json_encode(['endpoint' => $endpoint->id]);
} elseif ($action === 'snapshot') {
    $endpoint = MockEndpoint::findOrFail((int) ($argv[3] ?? 0));
    echo json_encode(['endpoint' => $endpoint->getAttributes(), 'responses' => $endpoint->responses()->orderBy('id')->get()->toArray(),
        'revisions' => Revision::where(fn ($query) => $query->where('entity_type', 'endpoint')->where('entity_id', $endpoint->id))
            ->orWhere(fn ($query) => $query->where('entity_type', 'response')->whereIn('entity_id', $endpoint->responses()->pluck('id')))->orderBy('id')->get()->toArray()]);
} else {
    throw new RuntimeException('Unknown fixture action.');
}
