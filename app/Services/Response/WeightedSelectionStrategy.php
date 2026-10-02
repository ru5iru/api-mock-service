<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class WeightedSelectionStrategy implements SelectionStrategyInterface
{
    public function __construct(private readonly WeightedRandomSelector $selector) {}

    public function select(MockEndpoint $endpoint, Collection $responses, Request $request, EndpointCallState $state): SelectionResult
    {
        return new SelectionResult($this->selector->select($responses));
    }
}
