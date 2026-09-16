<?php

return [
    /*
    | The control header allows callers that cannot preserve the original Host
    | header to tell MockDeck which absolute URL should participate in matching.
    | It is removed before request headers are canonicalized.
    */
    'original_url_header' => env('MOCK_ORIGINAL_URL_HEADER', 'X-Mock-Original-Url'),

    'auth_header_names' => [
        'authorization',
        'proxy-authorization',
    ],

    'max_delay_ms' => (int) env('MOCK_MAX_DELAY_MS', 30000),
    'log_tail_lines' => (int) env('MOCK_LOG_TAIL_LINES', 100),
    'log_tail_max_bytes' => (int) env('MOCK_LOG_TAIL_MAX_BYTES', 524288),
];
