<?php

namespace App\Services\Mutations;

final readonly class SelectionInput
{
    /** @param list<int> $responseOrder @param array<int, list<array<string, mixed>>> $responseRules */
    public function __construct(
        public string $selectionMode = 'weighted',
        public string $sequenceOnExhaust = 'repeat_last',
        public array $responseOrder = [],
        public ?int $fallbackResponseId = null,
        public array $responseRules = [],
    ) {}
}
