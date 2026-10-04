<?php

namespace App\Services\Response;

final readonly class FaultPlan
{
    public function __construct(public string $type, public int $delayMs = 0) {}
}
