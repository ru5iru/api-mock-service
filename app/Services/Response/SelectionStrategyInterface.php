<?php

namespace App\Services\Response;

use App\Models\EndpointCallState;
use App\Models\MockEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface SelectionStrategyInterface
{
    public function select(MockEndpoint $endpoint, Collection $responses, Request $request, EndpointCallState $state): SelectionResult;
}
