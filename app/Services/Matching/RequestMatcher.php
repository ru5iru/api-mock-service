<?php

namespace App\Services\Matching;

use App\Models\MockEndpoint;
use App\Services\Curl\ParsedCurl;

interface RequestMatcher
{
    public function matchEndpoint(MockEndpoint $endpoint, ParsedCurl $request): ?EndpointMatch;
}
