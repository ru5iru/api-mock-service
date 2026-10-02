<?php

use App\Models\EndpointCallState;
use App\Models\EnvironmentVariable;
use App\Models\MockResponse;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
$initialVariables = EnvironmentVariable::query()->count();
$input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$importer = app(ConfigImporter::class);
$plan = $importer->preview($input['export'], ImportMode::CreateOnly);
if (! $plan->canApply()) {
    throw new RuntimeException(json_encode($plan->errors));
}
$importer->apply($plan->token, $plan->digest, true);
$statesBeforeInvocation = EndpointCallState::query()->count();
$template = MockResponse::query()->sole()->template;
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::create('http://localhost/wave1-fresh-import', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'imported-fresh'], content: $input['body']);
$response = $kernel->handle($request);
echo json_encode(['initial_variables' => $initialVariables, 'states_before_invocation' => $statesBeforeInvocation, 'template' => $template, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
