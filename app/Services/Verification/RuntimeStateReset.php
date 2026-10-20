<?php

namespace App\Services\Verification;

use Illuminate\Database\Eloquent\Builder;

final class RuntimeStateReset
{
    public function reset(Builder $scope): int
    {
        return $scope->update(['total_match_count' => 0, 'sequence_position' => 0, 'last_matched_at' => null, 'recent_call_digests' => '[]']);
    }
}
