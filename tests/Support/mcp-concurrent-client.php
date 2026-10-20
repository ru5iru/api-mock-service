<?php

use Illuminate\Contracts\Console\Kernel;
use Laravel\Mcp\Client;
use Laravel\Mcp\Enums\ProtocolVersion;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$deadline = microtime(true) + 20;
while (! file_exists($argv[1])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Client barrier timed out.');
    }
    usleep(1000);
}
$client = Client::web($argv[2])->withToken(getenv('MCP_TEST_TOKEN'))->withProtocolVersion(ProtocolVersion::V2025_11_25);
$result = $client->callTool($argv[3], json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR));
echo json_encode($result->structuredContent, JSON_THROW_ON_ERROR);
