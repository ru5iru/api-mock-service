<?php

namespace App\Services\Templates;

final readonly class TemplateValidationResult
{
    /** @param list<TemplateIssue> $issues */
    public function __construct(
        public ?CompiledTemplate $compiled,
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{severity: string, code: string, path: string, line: int, col: int, message: string, suggestion: string|null}> */
    public function issueArrays(): array
    {
        return array_map(static fn (TemplateIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
