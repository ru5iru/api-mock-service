<?php

namespace App\Services\Curl;

use InvalidArgumentException;

/**
 * Converts a pasted curl command into the request shape consumed by the
 * canonicalizer. It intentionally parses text without invoking a shell.
 */
final class CurlParser
{
    /** @var list<string> */
    private const BODY_OPTIONS = [
        '--data', '--data-ascii', '--data-binary', '--data-raw', '--data-urlencode', '--json', '-d',
    ];

    /** @var list<string> */
    private const VALUE_OPTIONS_TO_IGNORE = [
        '--cacert', '--capath', '--cert', '--cert-type', '--connect-timeout', '--interface', '--key',
        '--key-type', '--limit-rate', '--max-time', '--output', '--proxy', '--proxy-user', '--request-target',
        '--resolve', '--retry', '--retry-delay', '--retry-max-time', '--tls-max', '--tls-user', '--trace',
        '--trace-ascii', '--unix-socket', '-o', '-x', '-Y', '-y',
    ];

    /** @var list<string> */
    private const FLAG_OPTIONS_TO_IGNORE = [
        '--compressed', '--fail', '--fail-with-body', '--http1.0', '--http1.1', '--http2', '--http2-prior-knowledge',
        '--insecure', '--location', '--location-trusted', '--no-progress-meter', '--path-as-is', '--silent',
        '--show-error', '--verbose', '-f', '-k', '-L', '-s', '-S', '-v',
    ];

    public function parse(string $command): ParsedCurl
    {
        $tokens = $this->tokenize(trim($command));

        if ($tokens === []) {
            throw new InvalidArgumentException('Paste a curl command to continue.');
        }

        if (in_array(strtolower(basename($tokens[0])), ['curl', 'curl.exe'], true)) {
            array_shift($tokens);
        }

        $method = null;
        $url = null;
        $headers = [];
        $bodyParts = [];
        $useGet = false;
        $head = false;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            [$option, $inlineValue] = $this->splitLongOption($token);

            if ($option === '--request' || $token === '-X' || str_starts_with($token, '-X')) {
                $method = strtoupper($token !== '-X' && str_starts_with($token, '-X')
                    ? substr($token, 2)
                    : $this->optionValue($tokens, $index, $inlineValue, $option));

                continue;
            }

            if ($option === '--url') {
                $url = $this->optionValue($tokens, $index, $inlineValue, $option);

                continue;
            }

            if ($option === '--header' || $token === '-H' || str_starts_with($token, '-H')) {
                $header = $token !== '-H' && str_starts_with($token, '-H')
                    ? substr($token, 2)
                    : $this->optionValue($tokens, $index, $inlineValue, $option);
                $headers[] = $this->parseHeader($header);

                continue;
            }

            if (in_array($option, self::BODY_OPTIONS, true) || $token === '-d' || str_starts_with($token, '-d')) {
                $value = $token !== '-d' && str_starts_with($token, '-d')
                    ? substr($token, 2)
                    : $this->optionValue($tokens, $index, $inlineValue, $option);

                if ($option !== '--data-raw' && str_starts_with($value, '@')) {
                    throw new InvalidArgumentException('File-backed curl request bodies are not supported; paste the file contents inline.');
                }

                if ($option === '--data-urlencode') {
                    $value = $this->encodeDataValue($value);
                }

                $bodyParts[] = $value;

                if ($option === '--json') {
                    $this->addHeaderUnlessPresent($headers, 'Content-Type', 'application/json');
                    $this->addHeaderUnlessPresent($headers, 'Accept', 'application/json');
                }

                continue;
            }

            if ($option === '--cookie' || $token === '-b' || str_starts_with($token, '-b')) {
                $value = $token !== '-b' && str_starts_with($token, '-b')
                    ? substr($token, 2)
                    : $this->optionValue($tokens, $index, $inlineValue, $option);
                if (! str_contains($value, '=')) {
                    throw new InvalidArgumentException('Cookie jar files are not supported; paste cookies as name=value pairs.');
                }
                $headers[] = ['name' => 'Cookie', 'value' => $value];

                continue;
            }

            if ($option === '--user' || $token === '-u' || str_starts_with($token, '-u')) {
                $value = $token !== '-u' && str_starts_with($token, '-u')
                    ? substr($token, 2)
                    : $this->optionValue($tokens, $index, $inlineValue, $option);
                $headers[] = ['name' => 'Authorization', 'value' => 'Basic '.base64_encode($value)];

                continue;
            }

            if ($option === '--oauth2-bearer') {
                $headers[] = [
                    'name' => 'Authorization',
                    'value' => 'Bearer '.$this->optionValue($tokens, $index, $inlineValue, $option),
                ];

                continue;
            }

            if ($option === '--user-agent' || $token === '-A' || str_starts_with($token, '-A')) {
                $headers[] = [
                    'name' => 'User-Agent',
                    'value' => $token !== '-A' && str_starts_with($token, '-A')
                        ? substr($token, 2)
                        : $this->optionValue($tokens, $index, $inlineValue, $option),
                ];

                continue;
            }

            if ($option === '--referer' || $token === '-e' || str_starts_with($token, '-e')) {
                $headers[] = [
                    'name' => 'Referer',
                    'value' => $token !== '-e' && str_starts_with($token, '-e')
                        ? substr($token, 2)
                        : $this->optionValue($tokens, $index, $inlineValue, $option),
                ];

                continue;
            }

            if ($option === '--get' || $token === '-G') {
                $useGet = true;

                continue;
            }

            if ($option === '--head' || $token === '-I') {
                $head = true;

                continue;
            }

            if ($option === '--form' || $option === '--form-string' || $token === '-F' || str_starts_with($token, '-F')) {
                throw new InvalidArgumentException('Multipart form curls are not supported because their generated boundaries are not stable.');
            }

            if (in_array($option, self::VALUE_OPTIONS_TO_IGNORE, true) || in_array($token, self::VALUE_OPTIONS_TO_IGNORE, true)) {
                $this->optionValue($tokens, $index, $inlineValue, $option);

                continue;
            }

            if (in_array($option, self::FLAG_OPTIONS_TO_IGNORE, true) || in_array($token, self::FLAG_OPTIONS_TO_IGNORE, true)) {
                continue;
            }

            if (str_starts_with($token, '-')) {
                throw new InvalidArgumentException("Unsupported curl option: {$token}");
            }

            if ($url !== null) {
                throw new InvalidArgumentException('The curl command contains more than one URL.');
            }

            $url = $token;
        }

        if ($url === null || trim($url) === '') {
            throw new InvalidArgumentException('The curl command does not contain a URL.');
        }

        $body = implode('&', $bodyParts);

        if ($useGet && $body !== '') {
            $url .= str_contains($url, '?') ? '&'.$body : '?'.$body;
            $body = '';
        }

        $method ??= $head ? 'HEAD' : ($useGet ? 'GET' : ($bodyParts === [] ? 'GET' : 'POST'));

        return new ParsedCurl(strtoupper($method), trim($url), $headers, $body);
    }

    /** @return array{string, ?string} */
    private function splitLongOption(string $token): array
    {
        if (! str_starts_with($token, '--') || ! str_contains($token, '=')) {
            return [$token, null];
        }

        return explode('=', $token, 2);
    }

    /** @param list<string> $tokens */
    private function optionValue(array $tokens, int &$index, ?string $inlineValue, string $option): string
    {
        if ($inlineValue !== null) {
            return $inlineValue;
        }

        if (! array_key_exists($index + 1, $tokens)) {
            throw new InvalidArgumentException("Curl option {$option} requires a value.");
        }

        return $tokens[++$index];
    }

    /** @return array{name: string, value: string} */
    private function parseHeader(string $header): array
    {
        if (! str_contains($header, ':')) {
            throw new InvalidArgumentException("Invalid curl header: {$header}");
        }

        [$name, $value] = explode(':', $header, 2);
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Header names cannot be empty.');
        }

        return ['name' => $name, 'value' => trim($value)];
    }

    /**
     * @param  list<array{name: string, value: string}>  $headers
     */
    private function addHeaderUnlessPresent(array &$headers, string $name, string $value): void
    {
        foreach ($headers as $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                return;
            }
        }

        $headers[] = ['name' => $name, 'value' => $value];
    }

    private function encodeDataValue(string $value): string
    {
        if (! str_contains($value, '=')) {
            return rawurlencode($value);
        }

        [$name, $data] = explode('=', $value, 2);

        return $name.'='.rawurlencode($data);
    }

    /** @return list<string> */
    private function tokenize(string $command): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $inToken = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];

            if ($quote === "'") {
                if ($character === "'") {
                    $quote = null;
                } else {
                    $current .= $character;
                }
                $inToken = true;

                continue;
            }

            if ($quote === '"') {
                if ($character === '"') {
                    $quote = null;

                    continue;
                }

                if ($character === '\\' && $index + 1 < $length) {
                    $next = $command[$index + 1];
                    if (in_array($next, ['\\', '"', '$', '`'], true)) {
                        $current .= $next;
                        $index++;

                        continue;
                    }
                    if ($next === "\n") {
                        $index++;

                        continue;
                    }
                }

                $current .= $character;
                $inToken = true;

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $inToken = true;

                continue;
            }

            if ($character === '\\') {
                if ($index + 1 >= $length) {
                    throw new InvalidArgumentException('The curl command ends with an incomplete escape.');
                }

                $next = $command[++$index];
                if ($next !== "\n") {
                    $current .= $next;
                    $inToken = true;
                }

                continue;
            }

            if (ctype_space($character)) {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }

                continue;
            }

            $current .= $character;
            $inToken = true;
        }

        if ($quote !== null) {
            throw new InvalidArgumentException('The curl command contains an unclosed quote.');
        }

        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
