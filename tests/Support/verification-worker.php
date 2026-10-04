<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.busy_timeout' => 30000]);
DB::purge('sqlite');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::create('http://localhost/verify-concurrent', 'GET', [], [], [], ['HTTP_X_CALL_NUMBER' => $argv[2]]);
$response = $kernel->handle($request);
fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]));
$kernel->terminate($request, $response);
