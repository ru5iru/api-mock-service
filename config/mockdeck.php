<?php

return [
    'rate_limits' => [
        // Requests per minute per IP allowed on unauthenticated mock-invocation endpoints.
        // Raise or lower via the MOCKDECK_INVOKE_RATE_LIMIT environment variable.
        'invoke_per_minute' => (int) env('MOCKDECK_INVOKE_RATE_LIMIT', 120),
    ],
];
