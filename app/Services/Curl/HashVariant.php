<?php

namespace App\Services\Curl;

/**
 * One of the five fixed candidate representations generated for a live call.
 */
final readonly class HashVariant
{
    public function __construct(
        public string $name,
        public string $normalized,
        public string $hash,
    ) {}
}
