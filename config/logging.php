<?php

use App\Logging\FlatJsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

$mockChannels = array_values(array_filter([
    env('MOCK_LOG_STDOUT', true) ? 'mock_requests_stdout' : null,
    env('MOCK_LOG_FILE', true) ? 'mock_requests_file' : null,
]));

if ($mockChannels === []) {
    $mockChannels[] = 'mock_requests_null';
}

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],
        'mock_requests' => [
            'driver' => 'stack',
            'channels' => $mockChannels,
            'ignore_exceptions' => true,
        ],
        'mock_requests_stdout' => [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stdout'],
            'formatter' => FlatJsonFormatter::class,
        ],
        'mock_requests_file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/mock-requests.log'),
            'level' => 'debug',
            'days' => (int) env('MOCK_LOG_DAYS', 14),
            'formatter' => FlatJsonFormatter::class,
        ],
        'mock_requests_null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];
