<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;

/** Local official MCP client example. Set MOCKDECK_MCP_URL and MOCKDECK_MCP_TOKEN. */
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$url = getenv('MOCKDECK_MCP_URL') ?: 'http://localhost:18473/mcp';
$token = getenv('MOCKDECK_MCP_TOKEN');
if (! $token) {
    throw new RuntimeException('Set MOCKDECK_MCP_TOKEN to a locally issued MCP bearer.');
}
$origin = getenv('MOCKDECK_MOCK_ORIGIN') ?: preg_replace('~/mcp/?$~', '', $url);
$client = Client::web($url)->withToken($token)->withProtocolVersion(ProtocolVersion::V2025_11_25);
$invoke = function (string $name, array $args) use ($client): array {
    $result = $client->callTool($name, $args);
    if ($result->isError) {
        throw new RuntimeException($result->text());
    }

    return $result->structuredContent['data'];
};
$run = null;
try {
    $tools = $client->tools();
    $run = $invoke('start_test_run', ['label' => 'local MCP example']);
    $scope = ['run_id' => $run['run_id']];
    $mock = $invoke('create_mock', [...$scope, 'idempotency_key' => 'example-create', 'configuration' => ['request' => ['url' => 'http://ignored.invalid/demo'], 'responses' => [['status' => 200, 'body' => '{"ok":true}']]]]);
    Http::withHeaders($mock['invocation']['header']['headers'])->get($origin.'/demo')->throw();
    Http::get($origin.'/_mockdeck/run/'.$run['run_id'].'/demo')->throw();
    $target = [...$scope, 'mock_id' => $mock['mock_id']];
    $calls = $invoke('get_calls', $target);
    $assertion = $invoke('assert_calls', [...$target, 'expect_count' => 2, 'comparator' => 'equals']);
    echo json_encode(['tools' => $tools->count(), 'run_id' => $run['run_id'], 'mock_id' => $mock['mock_id'], 'total_match_count' => $calls['total_match_count'], 'passed' => $assertion['passed']], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($run !== null) {
        $invoke('finish_test_run', ['run_id' => $run['run_id'], 'cleanup' => 'delete_mocks']);
    }
    $client->disconnect();
}
