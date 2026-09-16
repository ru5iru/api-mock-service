<?php

namespace Tests\Unit;

use App\Models\MockResponse;
use App\Services\Response\WeightedRandomSelector;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class WeightedRandomSelectorTest extends TestCase
{
    public function test_single_response_is_always_returned(): void
    {
        $response = new MockResponse(['weight' => 99]);

        self::assertSame($response, (new WeightedRandomSelector)->select(collect([$response])));
    }

    public function test_distribution_tracks_configured_weights(): void
    {
        $one = new MockResponse(['weight' => 1]);
        $three = new MockResponse(['weight' => 3]);
        $responses = new Collection([$one, $three]);
        $selector = new WeightedRandomSelector(new Randomizer(new Mt19937(12345)));
        $threeCount = 0;
        $runs = 20_000;

        for ($iteration = 0; $iteration < $runs; $iteration++) {
            if ($selector->select($responses) === $three) {
                $threeCount++;
            }
        }

        $ratio = $threeCount / $runs;
        self::assertGreaterThan(0.73, $ratio);
        self::assertLessThan(0.77, $ratio);
    }
}
