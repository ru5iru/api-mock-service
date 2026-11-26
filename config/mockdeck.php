<?php

return [
    'rate_limits' => [
        // Requests per minute per IP allowed on unauthenticated mock-invocation endpoints.
        // Raise or lower via the MOCKDECK_INVOKE_RATE_LIMIT environment variable.
        'invoke_per_minute' => (int) env('MOCKDECK_INVOKE_RATE_LIMIT', 120),

        // Requests per minute per IP on the token-authenticated verification API.
        // Limits damage from a stolen VERIFICATION_API_TOKEN.
        'verification_per_minute' => (int) env('MOCKDECK_VERIFY_RATE_LIMIT', 60),
    ],
];
