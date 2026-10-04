<?php

namespace Tests\Unit;

use App\Services\Curl\CurlHasher;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\PathPattern;
use InvalidArgumentException;
use Tests\TestCase;

final class FieldCanonicalizationTest extends TestCase
{
    public function test_exclusions_use_exact_decoded_query_names_case_insensitive_headers_and_keep_other_values(): void
    {
        $request = new ParsedCurl('GET', 'https://example.test/?nonce=one&nonce=two&Nonce=keep&first%20name=omit&a=1', [
            ['name' => 'X-Trace', 'value' => 'one'], ['name' => 'X-TRACE', 'value' => 'two'], ['name' => 'X-Keep', 'value' => 'yes'],
        ]);
        $value = app(CurlHasher::class)->forOptions($request, false, false, false, ['nonce', 'first name'], ['X-Trace']);
        self::assertSame("GET\n/?Nonce=keep&a=1\nx-keep:yes\n\n", $value->normalized);
        self::assertSame(hash('sha256', $value->normalized), $value->hash);
        self::assertSame('V6', $value->name);
    }

    public function test_legacy_variants_have_identical_explicit_canonical_bytes_with_empty_new_options(): void
    {
        $request = new ParsedCurl('GET', 'https://example.test/items?z=1&a=2', [
            ['name' => 'Cookie', 'value' => 'session=one'], ['name' => 'Authorization', 'value' => 'Bearer abc'], ['name' => 'X-Keep', 'value' => 'yes'],
        ]);
        $variants = app(CurlHasher::class)->variants($request);
        foreach ([[false, false, false, 'V1', "authorization:Bearer abc\ncookie:session=one\nx-keep:yes\n"], [true, false, false, 'V2', "authorization:Bearer abc\nx-keep:yes\n"], [false, true, false, 'V3', "cookie:session=one\nx-keep:yes\n"], [true, true, false, 'V4', "x-keep:yes\n"], [false, false, true, 'V5', '']] as [$cookies,$auth,$headers,$name,$head]) {
            $expected = "GET\n/items?a=2&z=1\n".$head."\n";
            $actual = app(CurlHasher::class)->forOptions($request, $cookies, $auth, $headers, [], [], false);
            self::assertSame($expected, $actual->normalized);
            self::assertSame(hash('sha256', $expected), $actual->hash);
            self::assertSame($name, $actual->name);
            self::assertEquals($variants[$name], $actual);
        }
    }

    public function test_pattern_segments_are_single_non_empty_segments_with_unique_names(): void
    {
        $patterns = new PathPattern;
        self::assertSame(['id' => '42', 'order_id' => 'a b'], $patterns->capture('/users/{id}/orders/{order_id}', '/users/42/orders/a%20b'));
        self::assertNull($patterns->capture('/users/{id}', '/users/42/orders'));
        self::assertNull($patterns->capture('/users/{id}', '/users/'));
        self::assertNull($patterns->capture('/users/{id}', '/Users/42'));
        foreach (['/users/{id}/{id}', '/users/prefix-{id}', '/users/{1name}', '/users/{bad-name}'] as $pattern) {
            try {
                $patterns->validate($pattern);
                self::fail('Invalid pattern accepted: '.$pattern);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
