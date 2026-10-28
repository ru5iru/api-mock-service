<?php

namespace Tests\Support;

use App\Models\EndpointCallState;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Support\Str;

final class HonestLabelsFixture
{
    public static function native(): array
    {
        $raw = "curl 'https://example.test/q02/existing'";
        $variant = app(CurlHasher::class)->forOptions(app(CurlParser::class)->parse($raw), false, true, false);
        $endpoint = MockEndpoint::factory()->create(['name' => 'Q02 before import', 'raw_curl' => $raw,
            'normalized_curl' => $variant->normalized, 'curl_hash' => $variant->hash,
            'exclude_cookies' => false, 'exclude_auth' => true, 'exclude_headers' => false]);
        $response = $endpoint->responses()->create(['body' => 'before import']);
        $removed = $endpoint->responses()->create(['body' => 'restore removed response']);
        $environment = Environment::query()->where('is_default', true)->firstOrFail();
        $environment->variables()->create(['key' => 'Q02_SEPARATE', 'value' => 'separately created', 'is_secret' => false]);
        EndpointCallState::query()->create(['endpoint_id' => $endpoint->id, 'environment_id' => $environment->id,
            'total_match_count' => 7, 'sequence_position' => 2, 'recent_call_digests' => []]);
        $document = app(ConfigExporter::class)->export([$endpoint->uuid], false)->data;
        $document['endpoints'][0]['name'] = 'Q02 after import';
        $document['endpoints'][0]['responses'] = [$document['endpoints'][0]['responses'][0]];
        $document['endpoints'][0]['responses'][0]['body'] = 'after import';
        $created = $document['endpoints'][0];
        $created['uuid'] = (string) Str::uuid();
        $created['name'] = 'Q02 retained new endpoint';
        $created['request']['curl'] = "curl 'https://example.test/q02/created'";
        $created['responses'][0]['uuid'] = (string) Str::uuid();
        $document['endpoints'][] = $created;

        return ['endpoint' => $endpoint->id, 'response' => $response->id, 'removed' => $removed->id, 'environment_id' => $environment->id,
            'created_uuid' => $created['uuid'], 'document' => $document];
    }
}
