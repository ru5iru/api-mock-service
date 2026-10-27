<?php

namespace Tests\Support;

use App\Models\CallbackAttempt;
use App\Services\Environments\EnvironmentContext;
use App\Services\Environments\EnvironmentVariableWriter;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\Revisions\RevisionManager;

final class SensitiveDataFixture
{
    public static function canaries(): array
    {
        return array_combine($keys = ['HDR-AUTH', 'HDR-PROXY', 'HDR-COOKIE', 'HDR-APIKEY', 'HDR-INTERNAL', 'QRY-TOKEN', 'QRY-PLAIN', 'BODY-JSON', 'BODY-FORM', 'TEMPLATE', 'CBURL', 'CBHDR', 'CBBODY', 'ENV-SECRET', 'ENV-PLAIN', 'SIGN', 'CURLURL'], array_map(fn ($key) => 'CANARY-'.$key.'-7f3a', $keys));
    }

    public static function create(): array
    {
        $c = self::canaries();
        $environment = app(EnvironmentContext::class)->active();
        foreach ([['API_TOKEN', 'ENV-SECRET', true], ['PUBLIC_VALUE', 'ENV-PLAIN', false]] as [$key, $canary, $secret]) {
            app(EnvironmentVariableWriter::class)->create($environment, ['key' => $key, 'value' => $c[$canary], 'is_secret' => $secret]);
        }
        $url = 'http://localhost/audit/'.$c['CURLURL'].'?token='.$c['QRY-TOKEN'].'&ref='.$c['QRY-PLAIN'];
        $headers = ['Authorization' => 'Bearer '.$c['HDR-AUTH'], 'Proxy-Authorization' => 'Basic '.$c['HDR-PROXY'], 'Cookie' => 'audit='.$c['HDR-COOKIE'], 'X-Api-Key' => $c['HDR-APIKEY'], 'X-Internal-Token' => $c['HDR-INTERNAL'], 'Content-Type' => 'application/json'];
        $body = json_encode(['password' => $c['BODY-JSON']]);
        $curl = "curl --request POST '$url'";
        foreach ($headers as $key => $value) {
            $curl .= " -H '$key: $value'";
        }
        $curl .= " --data '$body'";
        $endpoint = app(EndpointMutationService::class)->save(new EndpointInput(name: 'S01 canary endpoint', rawCurl: $curl, excludeHeaders: true));
        $response = $endpoint->responses()->create(['body' => $c['TEMPLATE'], 'template' => json_encode(['literal' => $c['TEMPLATE']]), 'callback_enabled' => true, 'callback_url' => 'http://127.0.0.1:9/hook?token='.$c['CBURL'], 'callback_headers' => ['Authorization' => 'Bearer '.$c['CBHDR']], 'callback_body' => json_encode(['literal' => $c['CBBODY']]), 'callback_signing_enabled' => true, 'callback_signing_secret' => $c['SIGN']]);
        $manager = app(RevisionManager::class);
        $manager->record($endpoint, $manager->snapshot($endpoint));
        $revision = $manager->record($response, $manager->snapshot($response));
        $response->update(['callback_signing_secret' => $c['SIGN'].'-changed']);
        $attempt = CallbackAttempt::query()->create(['response_id' => $response->id, 'request_log_id' => 's01-canary-attempt', 'attempt_number' => 1, 'target_url' => $response->callback_url, 'resolved_headers' => $headers, 'resolved_body' => $c['CBBODY'], 'method' => 'POST', 'status' => 'failed', 'error' => 'Bearer '.$c['CBHDR']]);

        return compact('endpoint', 'response', 'environment', 'revision', 'attempt', 'url', 'headers', 'body', 'curl');
    }
}
