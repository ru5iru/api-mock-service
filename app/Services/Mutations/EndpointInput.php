<?php

namespace App\Services\Mutations;

final readonly class EndpointInput
{
    public function __construct(
        public string $name = '',
        public bool $enabled = true,
        public int $priority = 0,
        public string $rawCurl = '',
        public bool $excludeCookies = false,
        public bool $excludeAuth = true,
        public bool $excludeHeaders = false,
        public array $excludedQueryParams = [],
        public array $excludedHeaderNames = [],
        public bool $pathPatternEnabled = false,
        public string $collectionId = '',
        public array $tagIds = [],
        public array $environmentOverrides = [],
    ) {}

    public function values(): array
    {
        return get_object_vars($this);
    }
}
