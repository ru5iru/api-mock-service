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

    'templates' => [
        'max_template_bytes' => (int) env('MOCK_TEMPLATE_MAX_BYTES', 262144),
        'max_depth' => (int) env('MOCK_TEMPLATE_MAX_DEPTH', 12),
        'max_nodes' => (int) env('MOCK_TEMPLATE_MAX_NODES', 10000),
        'max_rendered_nodes' => (int) env('MOCK_TEMPLATE_MAX_RENDERED_NODES', 100000),
        'max_repeat' => (int) env('MOCK_TEMPLATE_MAX_REPEAT', 1000),
        'max_args_bytes' => (int) env('MOCK_TEMPLATE_MAX_ARGS_BYTES', 4096),
        'max_output_bytes' => (int) env('MOCK_TEMPLATE_MAX_OUTPUT_BYTES', 1048576),
        'locales' => ['en', 'en_US', 'en_GB', 'fr_FR', 'de_DE', 'es_ES', 'it_IT', 'ja_JP'],
    ],

    'portable_config' => [
        'generator_version' => '1.2.0',
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
