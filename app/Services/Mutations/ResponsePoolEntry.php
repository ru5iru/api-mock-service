<?php

namespace App\Services\Mutations;

/** One final pool member; null ID means create, omitted existing IDs mean delete. */
final readonly class ResponsePoolEntry
{
    public function __construct(
        public ?int $id,
        public ResponseInput $content,
        public ?int $sequenceOrder = null,
        public bool $isDefault = false,
        public array $ruleConditions = [],
        public bool $writeContent = true,
    ) {}

    public function selection(): array
    {
        return ['sequence_order' => $this->sequenceOrder, 'is_default' => $this->isDefault, 'response_rules' => $this->ruleConditions];
    }
}
