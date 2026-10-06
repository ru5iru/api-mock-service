<?php

namespace Tests\Unit;

use App\Services\Response\ResponseHeaderPolicy;
use PHPUnit\Framework\TestCase;

final class ResponseHeaderPolicyTest extends TestCase
{
    public function test_current_server_owns_framing_and_connection_headers(): void
    {
        $headers = ['TRANSFER-ENCODING' => 'chunked', 'Content-Length' => '999', 'Connection' => 'keep-alive, X-Transport', 'Keep-Alive' => 'timeout=5', 'x-transport' => 'old', 'Content-Type' => 'application/json', 'Set-Cookie' => 'session=example', 'X-Mock' => 'yes'];
        self::assertSame(['Content-Type' => 'application/json', 'Set-Cookie' => 'session=example', 'X-Mock' => 'yes'], (new ResponseHeaderPolicy)->forServing($headers));
    }

    public function test_only_decoded_postman_examples_drop_the_original_content_encoding(): void
    {
        $headers = ['Content-Encoding' => 'gzip', 'Content-Type' => 'application/json'];
        self::assertSame($headers, (new ResponseHeaderPolicy)->forServing($headers));
        self::assertSame(['Content-Type' => 'application/json'], (new ResponseHeaderPolicy)->forServing($headers, true));
    }
}
