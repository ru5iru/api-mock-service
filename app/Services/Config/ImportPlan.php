<?php

namespace App\Services\Config;

final readonly class ImportPlan
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $token,
        public string $digest,
        public ImportMode $mode,
        public bool $replaceResponses,
        public array $counts,
        public array $items,
        public array $errors,
        public array $warnings,
    ) {}

    public function canApply(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'digest' => $this->digest,
            'mode' => $this->mode->value,
            'replace_responses' => $this->replaceResponses,
            'counts' => $this->counts,
            'items' => $this->items,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'can_apply' => $this->canApply(),
        ];
    }
}
