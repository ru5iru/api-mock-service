<?php

namespace App\Services\Templates;

final readonly class TemplateRenderResult
{
    public function __construct(
        public mixed $output,
        public string $json,
        public int $bytes,
        public float $renderMs,
    ) {}
}
