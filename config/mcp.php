<?php

return [
    // Named throttles are registered now; no route/transport is installed in Phase 1.
    'rate_limits' => [
        'auth_per_minute' => 30,
        'tools_per_minute' => 60,
    ],
];
