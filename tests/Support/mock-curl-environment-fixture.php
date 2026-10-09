<?php

use App\Models\MockEndpoint;
use App\Services\Config\ImportMode;
use App\Services\Environments\EnvironmentContext;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1' || app()->environment('production')) {
    throw new RuntimeException('This fixture requires a disposable test database.');
}
$environment = app(EnvironmentContext::class)->active();
$id = $environment->variables()->updateOrCreate(['key' => 'copy_login_id'], ['value' => 'user+one & 42%', 'is_secret' => false]);
$token = $environment->variables()->updateOrCreate(['key' => 'copy_token'], ['value' => "saved-token's-value", 'is_secret' => true]);
$json = json_encode(['info' => ['name' => 'Copy Environment QA', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'], 'item' => [[
    'name' => 'Copy Environment QA', 'request' => ['method' => 'GET', 'url' => '/copy-environment-qa?loginId={{copy_login_id}}&literal=a%2Bb', 'header' => [['key' => 'Authorization', 'value' => 'Bearer {{copy_token}}']]],
    'response' => [['code' => 200, 'body' => '{"copied":true}']],
]]], JSON_THROW_ON_ERROR);
$imports = app(MappedImportService::class);
$plan = $imports->preview(app(PostmanMapper::class), $json, ImportMode::Upsert);
$imports->apply($plan->token, $plan->digest);
$endpoint = MockEndpoint::query()->where('name', 'Copy Environment QA')->firstOrFail();
echo json_encode(['endpoint' => $endpoint->id, 'environment' => $environment->id, 'login_variable' => $id->id, 'token_variable' => $token->id], JSON_THROW_ON_ERROR);
