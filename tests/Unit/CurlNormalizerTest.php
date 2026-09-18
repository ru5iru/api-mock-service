<?php

namespace Tests\Unit;

use App\Services\Curl\CurlNormalizer;
use App\Services\Curl\ParsedCurl;
use InvalidArgumentException;
use Tests\TestCase;

final class CurlNormalizerTest extends TestCase
{
    public function test_it_sorts_query_headers_and_nested_json_keys(): void
    {
        $request = new ParsedCurl(
            'post',
            'HTTPS://API.EXAMPLE.TEST/users?b=two&a=one',
            [
                ['name' => 'Z-Last', 'value' => ' final '],
                ['name' => 'Content-Type', 'value' => 'application/json; charset=utf-8'],
                ['name' => 'X-Alpha', 'value' => 'first'],
            ],
            '{ "z": 1, "a": { "d": 4, "c": 3 } }',
        );

        $normalized = (new CurlNormalizer)->normalize($request);

        self::assertSame(<<<'CANONICAL'
POST
/users?a=one&b=two
content-type:application/json; charset=utf-8
x-alpha:first
z-last:final

{"a":{"c":3,"d":4},"z":1}
CANONICAL, $normalized->value);
        self::assertSame(hash('sha256', $normalized->value), $normalized->hash);
    }

    public function test_it_preserves_duplicate_query_value_order_while_sorting_keys(): void
    {
        $request = new ParsedCurl('GET', 'https://example.test/?z=1&a=first&a=second', []);

        $normalized = (new CurlNormalizer)->normalize($request);

        self::assertStringContainsString('/?a=first&a=second&z=1', $normalized->value);
    }

    public function test_scheme_host_and_port_do_not_change_the_signature(): void
    {
        $first = new ParsedCurl('GET', 'https://api.example.test:8443/items?limit=10', []);
        $second = new ParsedCurl('GET', 'http://localhost:18473/items?limit=10', []);

        self::assertSame(
            (new CurlNormalizer)->normalize($first)->hash,
            (new CurlNormalizer)->normalize($second)->hash,
        );
    }

    public function test_client_generated_transport_headers_are_ignored(): void
    {
        $request = new ParsedCurl('GET', 'https://example.test/items', [
            ['name' => 'Host', 'value' => 'example.test'],
            ['name' => 'Accept', 'value' => '*/*'],
            ['name' => 'User-Agent', 'value' => 'curl/8.0'],
            ['name' => 'X-Request-ID', 'value' => 'request-123'],
            ['name' => 'X-Version', 'value' => '2'],
        ]);

        $normalized = (new CurlNormalizer)->normalize($request);

        self::assertStringNotContainsString('host:', $normalized->value);
        self::assertStringNotContainsString('user-agent:', $normalized->value);
        self::assertStringNotContainsString('x-request-id:', $normalized->value);
        self::assertStringContainsString('x-version:2', $normalized->value);
    }

    public function test_custom_headers_are_not_given_control_header_behavior(): void
    {
        $normalized = (new CurlNormalizer)->normalize(new ParsedCurl(
            'GET',
            'https://example.test/items',
            [['name' => 'X-Upstream-Url', 'value' => 'https://another.example/items']],
        ));

        self::assertStringContainsString(
            'x-upstream-url:https://another.example/items',
            $normalized->value,
        );
    }

    public function test_invalid_json_falls_back_to_trimmed_raw_body(): void
    {
        $request = new ParsedCurl(
            'POST',
            'https://example.test/',
            [['name' => 'Content-Type', 'value' => 'application/json']],
            '  {not-json}  ',
        );

        $normalized = (new CurlNormalizer)->normalize($request, excludeHeaders: true);

        self::assertStringEndsWith("\n\n{not-json}", $normalized->value);
    }

    public function test_it_rejects_relative_non_http_and_credentialed_urls(): void
    {
        $normalizer = new CurlNormalizer;

        foreach (['/relative', 'ftp://example.test/file', 'https://user:secret@example.test/'] as $url) {
            try {
                $normalizer->normalize(new ParsedCurl('GET', $url, []));
                self::fail("Expected {$url} to be rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
