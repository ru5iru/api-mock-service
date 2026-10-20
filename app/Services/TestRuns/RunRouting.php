<?php

namespace App\Services\TestRuns;

use App\Services\Curl\ParsedCurl;
use InvalidArgumentException;

final class RunRouting
{
    public const HEADER = 'X-MockDeck-Test-Run';

    public const PREFIX = '/_mockdeck/run/';

    public static function reservedPath(string $path): bool
    {
        $path = rawurldecode($path);

        return $path === '/mcp' || str_starts_with($path, '/mcp/')
            || $path === '/_mockdeck' || str_starts_with($path, '/_mockdeck/');
    }

    public static function validateConfiguration(ParsedCurl $request): void
    {
        if (self::reservedPath((string) (parse_url($request->url, PHP_URL_PATH) ?: '/'))) {
            throw new InvalidArgumentException('The /_mockdeck and /mcp paths are reserved for MockDeck routing. Choose a different mock path.');
        }
        foreach ($request->headers as $header) {
            if (strcasecmp($header['name'], self::HEADER) === 0) {
                throw new InvalidArgumentException(self::HEADER.' is routing metadata and cannot be configured as a mock header.');
            }
        }
    }
}
