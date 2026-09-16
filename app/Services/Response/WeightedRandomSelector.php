<?php

namespace App\Services\Response;

use App\Models\MockResponse;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Random\Randomizer;
use RuntimeException;

/**
 * Chooses one configured response with probability proportional to its weight.
 */
final class WeightedRandomSelector implements ResponseSelectorInterface
{
    public function __construct(private readonly ?Randomizer $randomizer = null) {}

    /** @param Collection<int, MockResponse> $responses */
    public function select(Collection $responses): MockResponse
    {
        if ($responses->isEmpty()) {
            throw new InvalidArgumentException('Cannot select from an empty response collection.');
        }

        if ($responses->count() === 1) {
            return $responses->first();
        }

        $totalWeight = $responses->sum(static fn (MockResponse $response): int => max(1, (int) $response->weight));
        $remaining = ($this->randomizer ?? new Randomizer)->getInt(1, $totalWeight);

        foreach ($responses as $response) {
            $remaining -= max(1, (int) $response->weight);
            if ($remaining <= 0) {
                return $response;
            }
        }

        throw new RuntimeException('Weighted response selection exhausted an invalid weight range.');
    }
}
