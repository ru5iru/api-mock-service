<?php

namespace App\Services\Matching;

final class MatchPrecedence
{
    /** @var array<string, int> */
    private const VARIANT_SPECIFICITY = [
        'V1' => 50,
        'V2' => 40,
        'V3' => 40,
        'V4' => 30,
        'V5' => 10,
    ];

    public static function specificity(string $variant): int
    {
        return self::VARIANT_SPECIFICITY[$variant] ?? 0;
    }
}
