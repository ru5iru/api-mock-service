<?php

namespace App\Services\Config;

final readonly class RedactedCurl
{
    public function __construct(
        public string $curl,
        public bool $changed,
    ) {}
}
