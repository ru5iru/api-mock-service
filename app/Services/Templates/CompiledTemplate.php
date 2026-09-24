<?php

namespace App\Services\Templates;

final readonly class CompiledTemplate
{
    /** @param list<TemplateIssue> $issues */
    public function __construct(
        public string $source,
        public mixed $root,
        public array $issues,
    ) {}
}
