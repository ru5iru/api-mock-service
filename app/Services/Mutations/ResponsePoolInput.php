<?php

namespace App\Services\Mutations;

/** A complete proposed pool, validated before any intermediate state is written. */
final readonly class ResponsePoolInput
{
    /** @param list<ResponsePoolEntry> $responses */
    public function __construct(
        public string $selectionMode,
        public ?string $sequenceOnExhaust,
        public array $responses,
    ) {}

    public function selection(): array
    {
        return ['selection_mode' => $this->selectionMode, 'sequence_on_exhaust' => $this->sequenceOnExhaust];
    }
}
