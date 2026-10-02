<?php

namespace App\Services\Response;

use App\Models\MockResponse;

final readonly class SelectionResult
{
    public function __construct(
        public ?MockResponse $response,
        public string $reason = 'selected',
        public ?int $sequencePosition = null,
    ) {}
}
