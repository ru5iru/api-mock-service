<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;

/**
 * Describes which matching tier found an endpoint and, for hash hits, which
 * exclusion variant represented the endpoint's saved policy.
 */
final readonly class EndpointMatch
{
    public function __construct(
        public MockEndpoint $endpoint,
        public string $tier,
        public ?string $variant,
    ) {}
}
