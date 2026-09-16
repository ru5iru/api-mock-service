<?php

namespace Tests\Unit;

use App\Services\Curl\MockCurlBuilder;
use App\Services\Curl\ParsedCurl;
use Tests\TestCase;

final class MockCurlBuilderTest extends TestCase
{
    public function test_it_replaces_the_origin_and_preserves_the_request(): void
    {
        config(['app.url' => 'http://localhost:18473']);

        $curl = (new MockCurlBuilder)->build(new ParsedCurl(
            'POST',
            'https://api.example.test/v1/items?limit=10',
            [
                ['name' => 'Content-Type', 'value' => 'application/json'],
                ['name' => 'Authorization', 'value' => 'Bearer replace-me'],
            ],
            '{"name":"Example"}',
        ));

        self::assertSame(<<<'CURL'
curl 'http://localhost:18473/v1/items?limit=10' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
CURL, $curl);
    }
}
