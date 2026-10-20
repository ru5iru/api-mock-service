<?php

return [
    // Applied to every /mcp request; configure trusted proxy handling before exposure.
    // Native clients normally omit Origin. Browser clients must use an explicit trusted origin.
    'allowed_origins' => [],
    'rate_limits' => [
        'auth_per_minute' => 30,
        'tools_per_minute' => 60,
    ],
];
