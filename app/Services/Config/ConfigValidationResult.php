<?php

namespace App\Services\Config;

final readonly class ConfigValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?ConfigDocument $document,
        public array $errors,
        public array $warnings,
    ) {}

    public function valid(): bool
    {
        return $this->document !== null && $this->errors === [];
    }
}
