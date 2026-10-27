<?php

use App\Services\Config\ConfigExporter;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SensitiveDataFixture;

if (getenv('MOCKDECK_BROWSER_FIXTURE') !== '1' || ! getenv('AUDIT_DB')) {
    throw new RuntimeException('Use a named disposable database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql' || DB::selectOne('select current_database() as name')->name !== getenv('AUDIT_DB')) {
    throw new RuntimeException('Disposable database identity mismatch.');
}
$f = SensitiveDataFixture::create();
$empty = app(EndpointMutationService::class)->save(new EndpointInput(name: 'S01 empty pool', rawCurl: 'curl http://localhost/audit/empty', excludeHeaders: true));
$form = app(EndpointMutationService::class)->save(new EndpointInput(name: 'S01 form', rawCurl: "curl http://localhost/audit/form --data 'password=".SensitiveDataFixture::canaries()['BODY-FORM']."'", excludeHeaders: true));
$form->responses()->create(['body' => 'form ok']);
$document = app(ConfigExporter::class)->export(redactSecrets: false)->data;
$document['endpoints'][0]['name'] = 'S01 imported update';
$new = $document['endpoints'][0];
$new['uuid'] = (string) Str::uuid7();
$new['name'] = 'S01 imported new';
$new['request']['curl'] = 'curl http://localhost/audit/imported';
$new['responses'] = [];
$document['endpoints'][] = $new;
echo json_encode(['endpoint' => $f['endpoint']->id, 'response' => $f['response']->id, 'url' => str_replace('http://localhost', '', $f['url']), 'headers' => $f['headers'], 'body' => $f['body'], 'canaries' => SensitiveDataFixture::canaries(), 'document' => $document], JSON_THROW_ON_ERROR);
