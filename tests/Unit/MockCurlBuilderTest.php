<?php

namespace Tests\Unit;

use App\Services\Curl\MockCurlBuilder;
use App\Services\Curl\ParsedCurl;
use InvalidArgumentException;
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
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
CURL, $curl);
    }

    public function test_it_preserves_non_get_method_and_shell_quotes(): void
    {
        config(['app.url' => 'https://mock.example.test/']);

        $curl = (new MockCurlBuilder)->build(new ParsedCurl(
            'DELETE',
            'https://api.example.test/v1/items/ada%27s',
            [['name' => 'X-Reason', 'value' => "owner's request"]],
        ));

        self::assertStringContainsString("curl 'https://mock.example.test/v1/items/ada%27s'", $curl);
        self::assertStringContainsString("--request 'DELETE'", $curl);
        self::assertStringContainsString("--header 'X-Reason: owner'\\''s request'", $curl);
    }

    public function test_it_rejects_an_invalid_mock_base_url(): void
    {
        config(['app.url' => '/relative']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_URL must be an absolute HTTP or HTTPS URL.');

        (new MockCurlBuilder)->build(new ParsedCurl('GET', 'https://api.example.test/health', []));
    }
}
