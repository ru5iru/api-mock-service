<?php

namespace App\Services\Response;

use App\Models\MockResponse;
use Illuminate\Support\Collection;

/**
 * Selection boundary for swapping weighted random behavior with future
 * round-robin or request-aware strategies without changing invocation code.
 */
interface ResponseSelectorInterface
{
    /** @param Collection<int, MockResponse> $responses */
    public function select(Collection $responses): MockResponse;
}
