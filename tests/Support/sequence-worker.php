<?php

use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Response\ResponseSelectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.busy_timeout' => 30000]);
DB::purge('sqlite');
if (($argv[4] ?? 'service') === 'http') {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('http://localhost/wave1-concurrent', 'GET');
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'order' => $body['order'] ?? null, 'request_id' => $response->headers->get('X-Request-ID'), 'body' => $response->getContent()]));
    $kernel->terminate($request, $response);
    exit(0);
}
$endpoint = MockEndpoint::query()->findOrFail($argv[2]);
$environment = Environment::query()->findOrFail($argv[3]);
$result = app(ResponseSelectionService::class)->select($endpoint, Request::create('/'), $environment);
fwrite(STDOUT, json_encode(['position' => $result->sequencePosition, 'order' => $result->response?->sequence_order]));
