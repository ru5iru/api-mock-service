<?php

return [
    'auth_header_names' => [
        'authorization',
        'proxy-authorization',
    ],

    // Client/proxy-generated transport metadata is unstable and therefore is
    // never part of a saved or incoming request signature.
    'transport_header_names' => [
        'host',
        'content-length',
        'transfer-encoding',
        'user-agent',
        'accept',
        'accept-language',
        'accept-charset',
        'accept-encoding',
        'connection',
        'x-request-id',
    ],

    'dashboard_auth' => [
        'enabled' => env('MOCK_DASHBOARD_AUTH_ENABLED', true),
        'username' => env('MOCK_DASHBOARD_USERNAME'),
        'password' => env('MOCK_DASHBOARD_PASSWORD'),
    ],

    'max_delay_ms' => (int) env('MOCK_MAX_DELAY_MS', 30000),
    'log_tail_lines' => (int) env('MOCK_LOG_TAIL_LINES', 100),
    'log_tail_max_bytes' => (int) env('MOCK_LOG_TAIL_MAX_BYTES', 524288),
];
