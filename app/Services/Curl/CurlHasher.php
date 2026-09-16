<?php

namespace App\Services\Curl;

/**
 * Generates the five fixed hashes required because an incoming request cannot
 * know which saved endpoint's cookie/auth/header exclusion policy should apply.
 * Matching all variants in one indexed query keeps that policy endpoint-local.
 */
final readonly class CurlHasher
{
    public function __construct(private CurlNormalizer $normalizer) {}

    /** @return array<string, HashVariant> */
    public function variants(ParsedCurl $request): array
    {
        return [
            'V1' => $this->variant('V1', $request, false, false, false),
            'V2' => $this->variant('V2', $request, true, false, false),
            'V3' => $this->variant('V3', $request, false, true, false),
            'V4' => $this->variant('V4', $request, true, true, false),
            'V5' => $this->variant('V5', $request, false, false, true),
        ];
    }

    public function forOptions(
        ParsedCurl $request,
        bool $excludeCookies,
        bool $excludeAuth,
        bool $excludeHeaders,
    ): HashVariant {
        $name = $this->variantName($excludeCookies, $excludeAuth, $excludeHeaders);

        return $this->variant($name, $request, $excludeCookies, $excludeAuth, $excludeHeaders);
    }

    public function variantName(bool $excludeCookies, bool $excludeAuth, bool $excludeHeaders): string
    {
        if ($excludeHeaders) {
            return 'V5';
        }

        return match ([$excludeCookies, $excludeAuth]) {
            [true, true] => 'V4',
            [true, false] => 'V2',
            [false, true] => 'V3',
            default => 'V1',
        };
    }

    private function variant(
        string $name,
        ParsedCurl $request,
        bool $excludeCookies,
        bool $excludeAuth,
        bool $excludeHeaders,
    ): HashVariant {
        $normalized = $this->normalizer->normalize(
            $request,
            $excludeCookies,
            $excludeAuth,
            $excludeHeaders,
        );

        return new HashVariant($name, $normalized->value, $normalized->hash);
    }
}
