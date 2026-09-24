<?php

namespace App\Services\Templates;

final readonly class TemplateIssue
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $path,
        public int $line,
        public int $col,
        public string $message,
        public ?string $suggestion = null,
    ) {}

    /** @return array{severity: string, code: string, path: string, line: int, col: int, message: string, suggestion: string|null} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'path' => $this->path,
            'line' => $this->line,
            'col' => $this->col,
            'message' => $this->message,
            'suggestion' => $this->suggestion,
        ];
    }
}
