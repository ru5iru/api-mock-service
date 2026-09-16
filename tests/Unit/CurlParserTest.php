<?php

namespace Tests\Unit;

use App\Services\Curl\CurlParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurlParserTest extends TestCase
{
    public function test_it_parses_common_curl_syntax_without_executing_it(): void
    {
        $curl = <<<'CURL'
curl --request POST 'https://api.example.test/users?b=2&a=1' \
  -H 'Content-Type: application/json' \
  -H 'X-Trace: alpha' \
  --cookie 'session=secret' \
  --data-raw '{"name":"Ada Lovelace"}'
CURL;

        $parsed = (new CurlParser)->parse($curl);

        self::assertSame('POST', $parsed->method);
        self::assertSame('https://api.example.test/users?b=2&a=1', $parsed->url);
        self::assertSame('{"name":"Ada Lovelace"}', $parsed->body);
        self::assertSame([
            ['name' => 'Content-Type', 'value' => 'application/json'],
            ['name' => 'X-Trace', 'value' => 'alpha'],
            ['name' => 'Cookie', 'value' => 'session=secret'],
        ], $parsed->headers);
    }

    public function test_json_option_sets_default_headers_and_post_method(): void
    {
        $parsed = (new CurlParser)->parse("curl --json '{\"ok\":true}' https://api.example.test/events");

        self::assertSame('POST', $parsed->method);
        self::assertContains(['name' => 'Content-Type', 'value' => 'application/json'], $parsed->headers);
        self::assertContains(['name' => 'Accept', 'value' => 'application/json'], $parsed->headers);
    }

    public function test_file_backed_bodies_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File-backed');

        (new CurlParser)->parse('curl --data @payload.json https://api.example.test/events');
    }
}
