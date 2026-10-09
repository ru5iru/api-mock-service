<?php

namespace App\Services\Curl;

use App\Services\Templates\TemplateContext;
use InvalidArgumentException;

/** Copy-time substitution only: saved signatures and captured bodies stay intact. */
final readonly class MockCurlEnvironmentResolver
{
    public function __construct(private TemplateContext $contexts) {}

    public function resolve(ParsedCurl $request): ParsedCurl
    {
        $context = null;
        $substitute = function (string $value, string $path) use (&$context): string {
            return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*\}\}/', function (array $match) use (&$context, $path): string {
                $context ??= $this->contexts->build([]);

                return (string) $this->contexts->resolve('env.'.$match[1], $context, $path);
            }, $value);
        };

        $url = $request->url;
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query)) {
            $parts = explode('&', $query);
            foreach ($parts as &$part) {
                $pair = explode('=', $part, 2);
                $value = urldecode($pair[1] ?? '');
                if (preg_match('/\{\{\s*[A-Za-z_][A-Za-z0-9_.-]*\s*\}\}/', $value) === 1) {
                    // Decode placeholders once, then encode the resolved value
                    // as one query value. '&', '+', '%' and spaces remain data.
                    $part = $pair[0].'='.rawurlencode($substitute($value, '/query/'.urldecode($pair[0])));
                }
            }
            unset($part);
            $offset = strpos($url, '?') + 1;
            $url = substr_replace($url, implode('&', $parts), $offset, strlen($query));
        }

        $headers = $request->headers;
        foreach ($headers as &$header) {
            $value = $header['value'];
            $basic = strcasecmp($header['name'], 'Authorization') === 0 && preg_match('/^Basic\s+(.+)$/i', $value, $match) === 1;
            $decoded = $basic ? base64_decode($match[1], true) : false;
            if ($decoded !== false && str_contains($decoded, '{{')) {
                $value = 'Basic '.base64_encode($substitute($decoded, '/headers/'.$header['name']));
            } else {
                $value = $substitute($value, '/headers/'.$header['name']);
            }
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Unsafe Environment value for header '.$header['name'].'. Remove line breaks or null bytes before copying.');
            }
            $header['value'] = $value;
        }
        unset($header);

        return new ParsedCurl($request->method, $url, $headers, $request->body);
    }
}
