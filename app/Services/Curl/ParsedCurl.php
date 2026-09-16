<?php

namespace App\Services\Curl;

use InvalidArgumentException;

/**
 * Immutable request description shared by dashboard curls and live HTTP calls.
 *
 * @phpstan-type Header array{name: string, value: string}
 */
final readonly class ParsedCurl
{
    /**
     * @param  list<array{name: string, value: string}>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body = '',
    ) {
        if ($this->method === '' || $this->url === '') {
            throw new InvalidArgumentException('A request method and URL are required.');
        }

        if (strlen($this->method) > 10 || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $this->method) !== 1) {
            throw new InvalidArgumentException('The HTTP method must be a valid token of at most 10 characters.');
        }
    }
}
