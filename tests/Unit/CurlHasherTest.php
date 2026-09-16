<?php

namespace Tests\Unit;

use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlNormalizer;
use App\Services\Curl\ParsedCurl;
use Tests\TestCase;

final class CurlHasherTest extends TestCase
{
    public function test_it_generates_all_five_exclusion_variants(): void
    {
        $request = new ParsedCurl(
            'GET',
            'https://example.test/data',
            [
                ['name' => 'Cookie', 'value' => 'sid=one'],
                ['name' => 'Authorization', 'value' => 'Bearer token'],
                ['name' => 'X-Version', 'value' => '2'],
            ],
        );

        $variants = (new CurlHasher(new CurlNormalizer))->variants($request);

        self::assertSame(['V1', 'V2', 'V3', 'V4', 'V5'], array_keys($variants));
        self::assertCount(5, array_unique(array_map(static fn ($variant): string => $variant->hash, $variants)));
        self::assertStringContainsString('cookie:sid=one', $variants['V1']->normalized);
        self::assertStringNotContainsString('cookie:', $variants['V2']->normalized);
        self::assertStringNotContainsString('authorization:', $variants['V3']->normalized);
        self::assertStringNotContainsString('cookie:', $variants['V4']->normalized);
        self::assertStringNotContainsString('authorization:', $variants['V4']->normalized);
        self::assertSame("GET\n/data\n\n", $variants['V5']->normalized);
    }

    public function test_endpoint_options_map_to_the_expected_variant_name(): void
    {
        $hasher = new CurlHasher(new CurlNormalizer);

        self::assertSame('V1', $hasher->variantName(false, false, false));
        self::assertSame('V2', $hasher->variantName(true, false, false));
        self::assertSame('V3', $hasher->variantName(false, true, false));
        self::assertSame('V4', $hasher->variantName(true, true, false));
        self::assertSame('V5', $hasher->variantName(true, true, true));
    }
}
