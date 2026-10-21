<?php

namespace App\Services\TestRuns;

use App\Models\Environment;
use App\Models\TestRun;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Database\Eloquent\Builder;

/** Explicit request-local scope; never changes EnvironmentContext's global active setting. */
final readonly class RequestScope
{
    public function __construct(public Environment $environment, public ?TestRun $run = null) {}

    public static function ordinary(): self
    {
        return new self(app(EnvironmentContext::class)->active());
    }

    public function constrain(Builder $query): Builder
    {
        return $query->withoutGlobalScope('ordinary')->where('test_run_id', $this->run?->id);
    }
}
