<?php

namespace App\Services\Imports;

use App\Services\Curl\CurlFormatter;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Curl\RequestCredentialPolicy;
use App\Services\Curl\RequestUrl;
use App\Services\Response\ResponseHeaderPolicy;
use InvalidArgumentException;
use JsonException;

/** Postman data is parsed only. Scripts and exported file paths are never executed or read. */
final readonly class PostmanMapper implements ImportMapper
{
    public function __construct(private CurlFormatter $formatter, private CurlParser $parser, private CurlHasher $hasher, private RequestCredentialPolicy $credentials, private RequestUrl $urls, private array $variableOptions = []) {}

    public function withVariableOptions(array $options): self
    {
        return new self($this->formatter, $this->parser, $this->hasher, $this->credentials, $this->urls, $options);
    }

    public function map(string $json, bool $maskSecrets = false): array
    {
        if (strlen($json) > config('mock.portable_config.max_bytes', 2097152)) {
            throw new InvalidArgumentException('The collection exceeds the configured upload limit.');
        }
        try {
            $data = json_decode($json, true, 2048, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The collection is not valid JSON: '.$e->getMessage());
        }
        if (! is_array($data) || ! is_array($data['info'] ?? null)
            || ! in_array($data['info']['schema'] ?? null, ['https://schema.getpostman.com/json/collection/v2.1.0/collection.json', 'http://schema.getpostman.com/json/collection/v2.1.0/collection.json'], true)) {
            throw new InvalidArgumentException('Only Postman Collection v2.1 is supported. Legacy/v1 and other versions are not supported; export this collection as v2.1.');
        }
        $title = $this->text($data['info']['name'] ?? 'Postman collection');
        $title = $this->label($title ?: 'Postman collection');
        $variables = new PostmanVariableCatalog;
        $rootVariables = $variables->declarations($data['variable'] ?? [], 'Collection');
        $variables->observe($rootVariables, 'Collection');
        $environmentValues = $variables->declarations($this->variableOptions['environment_file_values'] ?? [], 'Environment file');
        $items = [];
        $folders = [];
        $responses = 0;
        $stack = [[$this->list($data['item'] ?? null, 'Collection item'), [], $data['auth'] ?? null, $data['event'] ?? [], $rootVariables]];
        while ($stack !== []) {
            [$entries, $path, $auth, $events, $scope] = array_pop($stack);
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    throw new InvalidArgumentException('Every collection item must be a request or a folder.');
                }
                $name = $this->text($entry['name'] ?? 'Untitled');
                if (! array_key_exists('request', $entry) && array_key_exists('item', $entry)) {
                    $folder = [...$path, $name];
                    $folderName = implode(' / ', $folder);
                    if (strlen($folderName) > 255) {
                        throw new InvalidArgumentException('A flattened folder path exceeds the 255-byte Collection name limit. Shorten folder names.');
                    }
                    $folders[] = $folderName;
                    $folderScope = [...$scope, ...$variables->declarations($entry['variable'] ?? [], 'Folder '.$folderName)];
                    $variables->observe($folderScope, $folderName);
                    $stack[] = [$this->list($entry['item'], 'Folder item'), $folder, $entry['auth'] ?? $auth, $entry['event'] ?? $events, $folderScope];
                } elseif (array_key_exists('request', $entry)) {
                    $request = is_string($entry['request']) ? ['url' => $entry['request'], 'method' => 'GET'] : $entry['request'];
                    if (! is_array($request)) {
                        throw new InvalidArgumentException('Each request must be an object or URL string.');
                    }
                    $warnings = [];
                    $effectiveEvents = $entry['event'] ?? $request['event'] ?? $events;
                    $variables->observe($scope, implode(' / ', $path).'/'.$name, json_encode([array_intersect_key($request, array_flip(['url', 'header', 'body'])), array_map(static fn ($example): array => is_array($example) ? array_intersect_key($example, array_flip(['body', 'header'])) : [], $this->list($entry['response'] ?? [], 'Saved responses')), $request['auth'] ?? $auth], JSON_THROW_ON_ERROR));
                    $mapped = $this->request($request, $request['auth'] ?? $auth, $warnings, $maskSecrets, implode(' / ', $path).'/'.$name);
                    $this->scripts($effectiveEvents, $warnings);
                    $examples = [];
                    foreach ($this->list($entry['response'] ?? [], 'Saved responses') as $example) {
                        if (! is_array($example)) {
                            throw new InvalidArgumentException('Each saved response must be an object.');
                        }
                        $headers = $this->headers($example['header'] ?? []);
                        $code = $example['code'] ?? null;
                        if (! is_int($code) || $code < 100 || $code > 599) {
                            foreach ($headers as $header) {
                                if (strcasecmp($header['name'], ':status') === 0 && preg_match('/^[1-5][0-9]{2}$/D', trim($header['value'])) === 1) {
                                    $code = (int) trim($header['value']);
                                    $warnings[] = 'Example status code read from HTTP/2 :status metadata; the pseudo-header is not imported.';
                                    break;
                                }
                            }
                        }
                        if (! is_int($code) || $code < 100 || $code > 599) {
                            $warnings[] = 'Invalid example status code; using 200.';
                            $code = 200;
                        }
                        $body = $this->text($example['body'] ?? '');
                        $body = $this->pretty($body, $warnings, 'example body');
                        if (is_array($example['originalRequest'] ?? null)) {
                            $ignored = [];
                            $original = $this->request([...$request, ...$example['originalRequest'], 'url' => $request['url']], $example['originalRequest']['auth'] ?? $request['auth'] ?? $auth, $ignored);
                            $currentWarnings = [];
                            $current = $maskSecrets ? $this->request($request, $request['auth'] ?? $auth, $currentWarnings) : $mapped;
                            $a = $this->parser->parse($current['raw_curl']);
                            $b = $this->parser->parse($original['raw_curl']);
                            $sort = static function (array $headers): array {
                                $headers = array_map(static fn ($h) => [strtolower($h['name']), trim($h['value'])], $headers);
                                sort($headers);

                                return $headers;
                            };
                            // Compare body meaning using the same canonicalizer as ordinary requests.
                            $bodyHash = fn (ParsedCurl $value): string => $this->hasher->forOptions(new ParsedCurl('GET', 'https://mockdeck.invalid/', $value->headers, $value->body), false, false, true)->hash;
                            if ($sort($a->headers) !== $sort($b->headers) || $bodyHash($a) !== $bodyHash($b)) {
                                $warnings[] = 'This example may be stale — its original request differs from the current request.';
                            }
                        }
                        $headerMap = [];
                        foreach ($headers as $header) {
                            if (! app(ResponseHeaderPolicy::class)->validName($header['name'])) {
                                $warnings[] = 'Example header '.$header['name'].' is protocol metadata or an invalid HTTP field name; not imported.';

                                continue;
                            }
                            if ($this->credentials->sensitive($header['name'], $header['value'])) {
                                $mapped['contains_secrets'] = true;
                                if ($maskSecrets) {
                                    $header['value'] = 'REPLACE_ME';
                                }
                            }
                            if (array_key_exists($header['name'], $headerMap)) {
                                $warnings[] = 'Repeated example header '.$header['name'].' uses its last value in the MockDeck response header map.';
                            }
                            $headerMap[$header['name']] = $header['value'];
                        }
                        $rewritten = app(PostmanResponseVariables::class)->rewrite($body, $warnings);
                        $examples[] = ['status_code' => $code, 'headers' => $headerMap, 'body' => $body, 'external_label' => isset($example['name']) ? $this->label($this->text($example['name'])) : null, 'weight' => 1, ...$rewritten];
                    }
                    if ($maskSecrets) {
                        $mapped['raw_curl'] = $this->credentials->maskCurl($mapped['raw_curl']);
                    }
                    if ($examples === []) {
                        $warnings[] = 'No saved responses: add a response before this endpoint can answer requests.';
                    }
                    $collection = $path === [] ? $title : implode(' / ', $path);
                    if (strlen($collection) > 255) {
                        throw new InvalidArgumentException('A flattened folder path exceeds the 255-byte Collection name limit. Shorten folder names.');
                    }
                    $key = implode(' / ', $path).'/'.$name.'|'.$mapped['method'].'|'.$mapped['path'];
                    $items[] = [...$mapped, 'name' => $this->label($name), 'collection' => $collection, 'correlation_key' => $key, 'responses' => $examples, 'warnings' => array_values(array_unique($warnings))];
                    $responses += count($examples);
                    if (count($items) > config('mock.portable_config.max_endpoints', 500) || $responses > config('mock.portable_config.max_responses', 5000)) {
                        throw new InvalidArgumentException('The collection exceeds the configured endpoint or response import limit.');
                    }
                } else {
                    throw new InvalidArgumentException('Every collection item must contain request or item.');
                }
            }
        }

        $documentWarnings = ['Renaming an item or moving it between folders changes its correlation key: a later import creates a new endpoint instead of updating it.'];
        $variableCandidates = $variables->candidates($environmentValues, $documentWarnings);
        foreach ($variableCandidates as &$candidate) {
            $candidate['exists_in_environment'] = array_key_exists($candidate['name'], $this->variableOptions['environment_values'] ?? []);
        }
        unset($candidate);

        return ['variable_candidates' => $variableCandidates, 'type' => 'postman', 'title' => $title, 'folders' => array_values(array_unique($folders)), 'items' => $items, 'warnings' => $documentWarnings];
    }

    private function request(array $request, mixed $auth, array &$warnings, bool $maskSecrets = false, string $fieldScope = ''): array
    {
        [$path, $query] = $this->url($request['url'] ?? null);
        $headers = $this->headers($request['header'] ?? []);
        $provenance = [];
        $excludeHeaders = [];
        $excludeQuery = [];
        $this->auth($auth, $headers, $query, $excludeHeaders, $warnings, $provenance);
        $names = [];
        $pattern = false;
        $parameterSegments = [];
        $segments = explode('/', $path);
        foreach ($segments as $segmentIndex => &$segment) {
            if (preg_match('/^\{\{([^{}]+)\}\}$/D', $segment, $match)) {
                $name = preg_replace('/[^A-Za-z0-9_]/', '_', $match[1]);
                if (! preg_match('/^[A-Za-z_]/', $name)) {
                    $name = 'param_'.$name;
                }
                $base = $name;
                $i = 2;
                while (in_array($name, $names, true)) {
                    $name = $base.'_'.$i++;
                }
                if ($name !== $match[1]) {
                    $warnings[] = 'Path variable '.$match[1].' mapped to {'.$name.'} to use a valid unique parameter name.';
                }
                $names[] = $name;
                $segment = '{'.$name.'}';
                $pattern = true;
                $parameterSegments[$segmentIndex] = true;
            } elseif (str_contains($segment, '{{')) {
                $warnings[] = 'Partial-segment variable '.$segment.' remains literal; convert the segment in the path-parameter editor if needed.';
            }
        }
        unset($segment);
        // Literal braces must be URL-encoded alongside real {name} parameters.
        if ($pattern) {
            foreach ($segments as $segmentIndex => &$segment) {
                if (! isset($parameterSegments[$segmentIndex])) {
                    $segment = str_replace(['{', '}'], ['%7B', '%7D'], $segment);
                }
            } unset($segment);
        }
        $path = implode('/', $segments);
        $secrets = false;
        $fieldResolver = new PostmanFieldResolver($this->variableOptions);
        foreach ($headers as &$header) {
            $tokens = (new PostmanVariableCatalog)->tokens($header['value']);
            $basic = collect($provenance)->first(fn ($entry) => $entry['field_name'] === strtolower($header['name']) && ($entry['auth_encoding'] ?? '') === 'basic');
            if ($basic !== null) {
                $tokens = $basic['tokens'];
                // Resolve Basic credentials before base64 encoding, just as exclusion detection does.
                $header['value'] = 'Basic '.base64_decode(substr($header['value'], 6));
            }
            if ($tokens !== []) {
                $sourceValue = $basic !== null ? substr($header['value'], 6) : $header['value'];
                $resolved = $fieldResolver->resolve($fieldScope, 'header', strtolower($header['name']), $sourceValue, $tokens);
                $header['value'] = $basic !== null ? 'Basic '.base64_encode($resolved['value']) : $resolved['value'];
                $provenance = array_values(array_filter($provenance, fn ($entry) => ! isset($entry['auth_encoding'])));
                $provenance[] = $resolved['field'];
                if ($resolved['field']['mode'] === 'exclude' || $resolved['field']['error']) {
                    $excludeHeaders[] = strtolower($header['name']);
                } else {
                    $excludeHeaders = array_values(array_diff($excludeHeaders, [strtolower($header['name'])]));
                }
            }
            $sensitive = $this->credentials->sensitive($header['name'], $header['value']);
            $secrets = $secrets || $sensitive;
            if ($maskSecrets && $sensitive) {
                $header['value'] = 'REPLACE_ME';
            }
        }
        unset($header);
        $pairs = [];
        foreach ($query as $parameter) {
            $tokens = (new PostmanVariableCatalog)->tokens($parameter['value']);
            if ($tokens !== []) {
                $resolved = $fieldResolver->resolve($fieldScope, 'query', $parameter['name'], $parameter['value'], $tokens);
                $parameter['value'] = $resolved['value'];
                $provenance[] = $resolved['field'];
                if ($resolved['field']['mode'] === 'exclude' || $resolved['field']['error']) {
                    $excludeQuery[] = $parameter['name'];
                }
            }
            $sensitive = $this->credentials->sensitive($parameter['name'], $parameter['value'], true);
            $secrets = $secrets || $sensitive;
            if ($maskSecrets && $sensitive) {
                $parameter['value'] = 'REPLACE_ME';
            }
            $pairs[] = rawurlencode($parameter['name']).'='.rawurlencode($parameter['value']);
        }
        $grouped = [];
        foreach ($provenance as $field) {
            $key = $field['field_type']."\0".$field['field_name'];
            $previous = $grouped[$key] ?? null;
            $field['tokens'] = array_values(array_unique([...($previous['tokens'] ?? []), ...$field['tokens']]));
            $field['error'] = $previous['error'] ?? $field['error'];
            $grouped[$key] = $field;
        }
        $provenance = array_values($grouped);
        foreach ($provenance as $entry) {
            $warnings[] = ucfirst($entry['field_type']).' '.$entry['field_name'].' variable tokens: '.implode(', ', $entry['tokens']).' ('.$entry['mode'].').';
        }
        foreach (array_unique($excludeHeaders) as $name) {
            $warnings[] = 'Header '.$name.' excluded: contains a Postman variable.';
        }
        foreach (array_unique($excludeQuery) as $name) {
            $warnings[] = 'Query parameter '.$name.' excluded: contains a Postman variable.';
        }
        $body = $this->body($request['body'] ?? [], $warnings);
        if (str_contains($body, '{{')) {
            $warnings[] = 'Body contains Postman variables left as literal text. Body matching is all-or-nothing; edit the body after import.';
        }
        // Postman can generate this header implicitly; the existing canonicalizer
        // selects JSON semantics through Content-Type, not a separate import-only body mode.
        if (in_array($request['body']['mode'] ?? 'none', ['raw', 'graphql'], true)
            && ! collect($headers)->contains(fn (array $header): bool => strcasecmp($header['name'], 'Content-Type') === 0)
            && trim($body) !== '') {
            try {
                json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                $headers[] = ['name' => 'Content-Type', 'value' => 'application/json'];
                $warnings[] = 'Added Content-Type: application/json for the JSON body; review this implicit Postman header.';
            } catch (JsonException) {
                // Invalid JSON deliberately retains the existing raw-text matching path.
            }
        }
        $url = 'https://mockdeck.invalid'.$path.($pairs !== [] ? '?'.implode('&', $pairs) : '');
        $parsed = new ParsedCurl(strtoupper($this->text($request['method'] ?? 'GET')), $url, $headers, $body);
        $variant = $this->hasher->forOptions($parsed, false, false, false, array_values(array_unique($excludeQuery)), array_values(array_unique($excludeHeaders)), $pattern);

        return ['variable_provenance' => $provenance, 'method' => $parsed->method, 'path' => $path, 'raw_curl' => $this->formatter->format($parsed), 'variant' => $variant->name, 'excluded_query_params' => array_values(array_unique($excludeQuery)), 'excluded_headers' => array_values(array_unique($excludeHeaders)), 'path_pattern_enabled' => $pattern, 'contains_secrets' => $secrets];
    }

    private function url(mixed $url): array
    {
        if (is_array($url) && (array_key_exists('path', $url) || array_key_exists('query', $url))) {
            $path = $url['path'] ?? [];
            $path = is_array($path) ? implode('/', array_map($this->text(...), $path)) : $this->text($path);
            $query = $this->headers($url['query'] ?? []);

            return ['/'.ltrim($path, '/'), $query];
        }
        $raw = $this->text(is_array($url) ? ($url['raw'] ?? '') : $url);
        // A leading base-URL variable is authority, not a wildcard path segment.
        $raw = preg_replace('/^\{\{[^{}]+\}\}(?=\/|\?|$)/', 'https://mockdeck.invalid', $raw);
        if (str_starts_with($raw, '/')) {
            $raw = 'https://mockdeck.invalid'.$raw;
        }
        $parsed = $this->parser->parse($this->formatter->format(new ParsedCurl('GET', $raw, [])));
        // Replace variable authority fields only; the shared URL parser still owns path/query parsing.
        $authorityNeutral = preg_replace('~^(https?://)[^/?#]*~i', 'https://mockdeck.invalid', $parsed->url);
        $parts = $this->urls->parse($authorityNeutral);
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[] = ['name' => urldecode($name), 'value' => urldecode($value)];
        }

        return [$parts['path'] ?? '/', $query];
    }

    private function auth(mixed $auth, array &$headers, array &$query, array &$excluded, array &$warnings, array &$provenance): void
    {
        if ($auth === null) {
            return;
        }
        if (! is_array($auth)) {
            throw new InvalidArgumentException('Auth must be an object.');
        }
        $type = $auth['type'] ?? 'noauth';
        if ($type === 'noauth') {
            return;
        }
        if (! in_array($type, ['bearer', 'basic', 'apikey'], true)) {
            $warnings[] = 'Auth type '.$this->text($type).' was not translated; it depends on an external authentication flow.';

            return;
        }
        $values = [];
        foreach ($this->list($auth[$type] ?? [], 'Auth attributes') as $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Invalid auth attribute.');
            } $values[$value['key'] ?? ''] = $this->text($value['value'] ?? '');
        }
        if ($type === 'apikey') {
            $name = $values['key'] ?? 'X-API-Key';
            $value = $values['value'] ?? '';
            if (($values['in'] ?? 'header') === 'query') {
                $query[] = ['name' => $name, 'value' => $value];

                return;
            }
        } else {
            $name = 'Authorization';
            if ($type === 'bearer') {
                $value = 'Bearer '.($values['token'] ?? '');
            } else {
                $plain = ($values['username'] ?? '').':'.($values['password'] ?? '');
                if ($this->variable($plain)) {
                    $excluded[] = 'authorization';
                    $provenance[] = ['field_type' => 'header', 'field_name' => 'authorization', 'tokens' => (new PostmanVariableCatalog)->tokens($plain), 'mode' => 'exclude', 'auth_encoding' => 'basic'];
                }
                $value = 'Basic '.base64_encode($plain);
            }
        }
        $headers = array_values(array_filter($headers, static fn ($header) => strcasecmp($header['name'], $name) !== 0));
        $headers[] = ['name' => $name, 'value' => $value];
    }

    private function body(mixed $body, array &$warnings): string
    {
        if (! is_array($body)) {
            throw new InvalidArgumentException('Request body must be an object.');
        }
        $mode = $body['mode'] ?? 'none';
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'raw') {
            return $this->pretty($this->text($body['raw'] ?? ''), $warnings, 'body');
        }
        if ($mode === 'graphql') {
            $graphql = $body['graphql'] ?? [];
            if (! is_array($graphql)) {
                throw new InvalidArgumentException('GraphQL body must be an object.');
            }
            $variables = $graphql['variables'] ?? new \stdClass;
            if (is_string($variables)) {
                try {
                    $variables = json_decode($variables ?: '{}', false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $warnings[] = 'GraphQL variables are not valid JSON; their literal text was retained.';
                }
            }

            return json_encode(['query' => $this->text($graphql['query'] ?? ''), 'variables' => $variables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if (in_array($mode, ['urlencoded', 'formdata'], true)) {
            $pairs = [];
            foreach ($this->list($body[$mode] ?? [], 'Body fields') as $field) {
                if (! is_array($field)) {
                    throw new InvalidArgumentException('Body fields must be objects.');
                }
                if (($field['disabled'] ?? false) === true) {
                    continue;
                }
                $value = $this->text($field['value'] ?? '');
                if (($field['type'] ?? 'text') === 'file') {
                    $value = '[file content not included]';
                    $warnings[] = 'File upload '.$this->text($field['key'] ?? '').' has no portable content; stored a placeholder.';
                }
                $pairs[] = rawurlencode($this->text($field['key'] ?? '')).'='.rawurlencode($value);
            }
            if ($mode === 'formdata') {
                $warnings[] = 'Form-data is represented as key=value text, not multipart bytes.';
            }

            return implode('&', $pairs);
        }
        $warnings[] = 'Body mode '.$this->text($mode).' is unsupported; file content was not read and the body is empty.';

        return '';
    }

    private function pretty(string $body, array &$warnings, string $label): string
    {
        if (trim($body) === '') {
            return $body;
        }
        try {
            return json_encode(json_decode($body, false, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $warnings[] = ucfirst($label).' is not valid JSON — '.($label === 'body' ? 'matched as raw text.' : 'stored as raw text.');

            return $body;
        }
    }

    private function headers(mixed $values): array
    {
        $result = [];
        foreach ($this->list($values, 'Headers/query') as $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Headers and query entries must be objects.');
            }
            if (($value['disabled'] ?? false) === true) {
                continue;
            }
            $result[] = ['name' => $this->text($value['key'] ?? ''), 'value' => $this->text($value['value'] ?? '')];
        }

        return $result;
    }

    private function scripts(mixed $events, array &$warnings): void
    {
        foreach ($this->list($events, 'Events') as $event) {
            if (! is_array($event)) {
                throw new InvalidArgumentException('Events must be objects.');
            }
            $exec = $event['script']['exec'] ?? [];
            $lines = is_array($exec) ? $exec : explode("\n", $this->text($exec));
            if (trim(implode("\n", array_map($this->text(...), $lines))) === '') {
                continue;
            }
            $type = ($event['listen'] ?? '') === 'prerequest' ? 'Pre-request' : 'Test';
            $warnings[] = $type.' script present ('.count($lines).' lines); not imported or executed.';
        }
    }

    private function variable(string $value): bool
    {
        return preg_match('/\{\{[^{}]+\}\}/', $value) === 1;
    }

    private function list(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($label.' must be an array.');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        } if (! is_scalar($value)) {
            throw new InvalidArgumentException('Expected a scalar Postman field.');
        }

        return (string) $value;
    }

    private function label(string $value): string
    {
        return mb_strcut($value, 0, 255, 'UTF-8');
    }
}
