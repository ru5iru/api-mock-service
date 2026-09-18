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

    'portable_config' => [
        'generator_version' => '1.1.0',
        'max_bytes' => (int) env('MOCK_CONFIG_MAX_BYTES', 2097152),
        'max_endpoints' => (int) env('MOCK_CONFIG_MAX_ENDPOINTS', 500),
        'max_responses' => (int) env('MOCK_CONFIG_MAX_RESPONSES', 5000),
        'max_string_bytes' => (int) env('MOCK_CONFIG_MAX_STRING_BYTES', 1048576),
        'preview_ttl_seconds' => (int) env('MOCK_CONFIG_PREVIEW_TTL', 900),
        'sensitive_headers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MOCK_EXPORT_SENSITIVE_HEADERS',
                'authorization,proxy-authorization,cookie,set-cookie,x-api-key,api-key,x-auth-token',
            )),
        ))),
        'sensitive_query_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MOCK_EXPORT_SENSITIVE_QUERY_KEYS',
                'access_token,api_key,apikey,auth_token,key,token',
            )),
        ))),
    ],
];
