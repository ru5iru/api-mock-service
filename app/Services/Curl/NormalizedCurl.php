<?php

namespace App\Services\Curl;

/**
 * Canonical request text plus the digest used by the indexed matching tier.
 */
final readonly class NormalizedCurl
{
    public function __construct(
        public string $value,
        public string $hash,
    ) {}
}
