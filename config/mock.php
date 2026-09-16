<?php

return [
    'auth_header_names' => [
        'authorization',
        'proxy-authorization',
    ],

    // Automatically supplied HTTP transport headers are not part of a curl's
    // explicit request definition and can change between clients/proxies.
    'transport_header_names' => [
        'host',
        'content-length',
        'user-agent',
        'accept',
        'accept-language',
        'accept-charset',
        'accept-encoding',
        'connection',
    ],

    'max_delay_ms' => (int) env('MOCK_MAX_DELAY_MS', 30000),
    'log_tail_lines' => (int) env('MOCK_LOG_TAIL_LINES', 100),
    'log_tail_max_bytes' => (int) env('MOCK_LOG_TAIL_MAX_BYTES', 524288),
];
