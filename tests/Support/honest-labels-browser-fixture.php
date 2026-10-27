<?php

use App\Models\CallbackAttempt;
use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\HonestLabelsFixture;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1') {
    throw new RuntimeException('Use a dedicated disposable fixture database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'inspect') {
    echo json_encode(['endpoints' => MockEndpoint::query()->orderBy('id')->get(['id', 'uuid', 'name'])->toArray(),
        'responses' => MockResponse::query()->orderBy('id')->get(['id', 'mock_endpoint_id', 'body'])->toArray(),
        'states' => EndpointCallState::query()->get()->toArray(),
        'variable' => Environment::query()->findOrFail((int) ($argv[2] ?? 0))->variables()->where('key', 'Q02_SEPARATE')->value('value')], JSON_THROW_ON_ERROR);
    exit;
}
$data = HonestLabelsFixture::native();
$editor = MockEndpoint::factory()->create(['name' => 'Q02 rule and callback editor', 'selection_mode' => 'rule']);
$rule = $editor->responses()->create(['body' => 'rule response', 'callback_url' => 'https://current.example.test/hook', 'external_label' => 'Current response label']);
$rule->rules()->create(['field_type' => 'query', 'field_name' => 'mode', 'operator' => 'equals', 'value' => 'test', 'priority' => 0]);
$editor->responses()->create(['body' => 'fallback', 'is_default' => true]);
CallbackAttempt::query()->create(['response_id' => $rule->id, 'target_url' => 'https://recorded.example.test/hook',
    'resolved_headers' => [], 'resolved_body' => '{}', 'method' => 'POST', 'attempt_number' => 1, 'status' => 'success', 'http_status' => 204]);
echo json_encode([...$data, 'editor' => $editor->id, 'rule' => $rule->id], JSON_THROW_ON_ERROR);
