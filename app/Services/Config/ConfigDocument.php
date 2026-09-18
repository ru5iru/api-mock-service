<?php

namespace App\Services\Config;

use JsonException;

final readonly class ConfigDocument
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public string $digest,
    ) {}

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @return list<array<string, mixed>> */
    public function endpoints(): array
    {
        /** @var list<array<string, mixed>> $endpoints */
        $endpoints = $this->data['endpoints'];

        return $endpoints;
    }
}
