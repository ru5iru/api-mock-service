<?php

namespace App\Services\Config;

use App\Services\Templates\ResponseTemplateEngine;
use Illuminate\Support\Str;
use JsonException;

final class ConfigValidator
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly ResponseTemplateEngine $templates) {}

    public function validate(string $json): ConfigValidationResult
    {
        $this->errors = [];
        $this->warnings = [];

        if (strlen($json) > config('mock.portable_config.max_bytes', 2097152)) {
            return new ConfigValidationResult(null, ['The configuration file exceeds the allowed size.'], []);
        }

        if (preg_match('//u', $json) !== 1) {
            return new ConfigValidationResult(null, ['The configuration file must be valid UTF-8.'], []);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new ConfigValidationResult(null, ['The configuration is not valid JSON: '.$exception->getMessage()], []);
        }

        if (! is_array($data) || array_is_list($data)) {
            return new ConfigValidationResult(null, ['The configuration root must be a JSON object.'], []);
        }

        $this->warnUnknown($data, [
            '$schema', 'format', 'format_version', 'exported_at', 'generator', 'options', 'warnings', 'endpoints',
        ], '$');

        if (($data['format'] ?? null) !== 'mockdeck') {
            $this->errors[] = '$.format must be exactly "mockdeck".';
        }

        if (! in_array($data['format_version'] ?? null, [1, '1.0', '1.1'], true)) {
            $this->errors[] = '$.format_version is not supported; this release accepts version 1, 1.0, or 1.1.';
        }

        if (! isset($data['endpoints']) || ! is_array($data['endpoints']) || ! array_is_list($data['endpoints'])) {
            $this->errors[] = '$.endpoints must be an array.';
        } else {
            $this->validateEndpoints($data['endpoints']);
        }

        if ($this->errors !== []) {
            return new ConfigValidationResult(null, $this->errors, $this->warnings);
        }

        /** @var array<string, mixed> $data */
        return new ConfigValidationResult(
            new ConfigDocument($data, hash('sha256', $json)),
            [],
            $this->warnings,
        );
    }

    /** @param list<mixed> $endpoints */
    private function validateEndpoints(array $endpoints): void
    {
        if (count($endpoints) > config('mock.portable_config.max_endpoints', 500)) {
            $this->errors[] = '$.endpoints exceeds the configured endpoint limit.';
        }

        $endpointUuids = [];
        $responseUuids = [];
        $responseCount = 0;

        foreach ($endpoints as $index => $endpoint) {
            $path = "$.endpoints[{$index}]";
            if (! is_array($endpoint) || array_is_list($endpoint)) {
                $this->errors[] = "{$path} must be an object.";

                continue;
            }

            $this->warnUnknown($endpoint, [
                'uuid', 'name', 'enabled', 'priority', 'requires_secret_replacement', 'request', 'responses',
            ], $path);

            $uuid = $endpoint['uuid'] ?? null;
            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                $this->errors[] = "{$path}.uuid must be a valid UUID.";
            } elseif (isset($endpointUuids[strtolower($uuid)])) {
                $this->errors[] = "{$path}.uuid is duplicated in this document.";
            } else {
                $endpointUuids[strtolower($uuid)] = true;
            }

            $name = $endpoint['name'] ?? null;
            if ($name !== null && (! is_string($name) || strlen($name) > 255)) {
                $this->errors[] = "{$path}.name must be null or a string of at most 255 bytes.";
            }

            if (! is_bool($endpoint['enabled'] ?? null)) {
                $this->errors[] = "{$path}.enabled must be a boolean.";
            }

            $priority = $endpoint['priority'] ?? null;
            if (! is_int($priority) || $priority < -1000 || $priority > 1000) {
                $this->errors[] = "{$path}.priority must be an integer between -1000 and 1000.";
            }

            if (isset($endpoint['requires_secret_replacement']) && ! is_bool($endpoint['requires_secret_replacement'])) {
                $this->errors[] = "{$path}.requires_secret_replacement must be a boolean.";
            }

            $this->validateRequest($endpoint['request'] ?? null, $path.'.request');

            $responses = $endpoint['responses'] ?? null;
            if (! is_array($responses) || ! array_is_list($responses)) {
                $this->errors[] = "{$path}.responses must be an array.";

                continue;
            }

            if ($responses === []) {
                $this->warnings[] = "{$path} has no responses and will return a configuration error when matched.";
            }

            $responseCount += count($responses);
            foreach ($responses as $responseIndex => $response) {
                $this->validateResponse($response, "{$path}.responses[{$responseIndex}]", $responseUuids);
            }
        }

        if ($responseCount > config('mock.portable_config.max_responses', 5000)) {
            $this->errors[] = 'The document exceeds the configured response limit.';
        }
    }

    private function validateRequest(mixed $request, string $path): void
    {
        if (! is_array($request) || array_is_list($request)) {
            $this->errors[] = "{$path} must be an object.";

            return;
        }

        $this->warnUnknown($request, ['curl', 'signature_version', 'matching'], $path);

        $curl = $request['curl'] ?? null;
        if (! is_string($curl) || trim($curl) === '') {
            $this->errors[] = "{$path}.curl must be a non-empty string.";
        } elseif (strlen($curl) > config('mock.portable_config.max_string_bytes', 1048576)) {
            $this->errors[] = "{$path}.curl exceeds the configured string limit.";
        }

        if (! is_int($request['signature_version'] ?? null) || $request['signature_version'] < 1) {
            $this->errors[] = "{$path}.signature_version must be a positive integer.";
        }

        $matching = $request['matching'] ?? null;
        if (! is_array($matching) || array_is_list($matching)) {
            $this->errors[] = "{$path}.matching must be an object.";

            return;
        }

        $this->warnUnknown($matching, ['exclude_cookies', 'exclude_auth', 'exclude_headers'], $path.'.matching');
        foreach (['exclude_cookies', 'exclude_auth', 'exclude_headers'] as $field) {
            if (! is_bool($matching[$field] ?? null)) {
                $this->errors[] = "{$path}.matching.{$field} must be a boolean.";
            }
        }

        if (($matching['exclude_headers'] ?? false) === true
            && (($matching['exclude_cookies'] ?? false) === true || ($matching['exclude_auth'] ?? false) === true)) {
            $this->errors[] = "{$path}.matching cannot combine exclude_headers with the narrower exclusion flags.";
        }
    }

    /** @param array<string, bool> $seenUuids */
    private function validateResponse(mixed $response, string $path, array &$seenUuids): void
    {
        if (! is_array($response) || array_is_list($response)) {
            $this->errors[] = "{$path} must be an object.";

            return;
        }

        $this->warnUnknown($response, [
            'uuid', 'status', 'headers', 'body', 'body_mode', 'template', 'editor_view', 'seed_mode', 'seed', 'locale', 'delay_ms', 'weight',
        ], $path);

        $uuid = $response['uuid'] ?? null;
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            $this->errors[] = "{$path}.uuid must be a valid UUID.";
        } elseif (isset($seenUuids[strtolower($uuid)])) {
            $this->errors[] = "{$path}.uuid is duplicated in this document.";
        } else {
            $seenUuids[strtolower($uuid)] = true;
        }

        $status = $response['status'] ?? null;
        if (! is_int($status) || $status < 100 || $status > 599) {
            $this->errors[] = "{$path}.status must be an integer between 100 and 599.";
        }

        $headers = $response['headers'] ?? null;
        if (! is_array($headers) || ($headers !== [] && array_is_list($headers))) {
            $this->errors[] = "{$path}.headers must be a JSON object.";
        } else {
            foreach ($headers as $name => $value) {
                if (! is_string($name)
                    || preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/', $name) !== 1
                    || ! is_scalar($value)
                    || preg_match('/[\r\n]/', (string) $value) === 1) {
                    $this->errors[] = "{$path}.headers contains an invalid name or value.";
                    break;
                }
            }
        }

        $body = $response['body'] ?? null;
        if (! is_string($body)) {
            $this->errors[] = "{$path}.body must be a string.";
        } elseif (strlen($body) > config('mock.portable_config.max_string_bytes', 1048576)) {
            $this->errors[] = "{$path}.body exceeds the configured string limit.";
        }

        $bodyMode = $response['body_mode'] ?? 'static';
        if (! in_array($bodyMode, ['static', 'template'], true)) {
            $this->errors[] = "{$path}.body_mode must be static or template.";
        }

        $template = $response['template'] ?? null;
        if ($template !== null && ! is_string($template)) {
            $this->errors[] = "{$path}.template must be null or a string.";
        } elseif (is_string($template) && strlen($template) > config('mock.templates.max_template_bytes', 262144)) {
            $this->errors[] = "{$path}.template exceeds the configured template limit.";
        } elseif ($bodyMode === 'template') {
            if (! is_string($template) || trim($template) === '') {
                $this->errors[] = "{$path}.template is required when body_mode is template.";
            } else {
                $validation = $this->templates->validate($template, (string) ($response['locale'] ?? 'en'));
                foreach ($validation->issues as $issue) {
                    $message = "{$path}.template{$issue->path}: {$issue->message}";
                    if ($issue->severity === 'error') {
                        $this->errors[] = $message;
                    } else {
                        $this->warnings[] = $message;
                    }
                }
            }
        }

        if (! in_array($response['editor_view'] ?? 'builder', ['builder', 'json'], true)) {
            $this->errors[] = "{$path}.editor_view must be builder or json.";
        }
        if (! in_array($response['seed_mode'] ?? 'random', ['random', 'fixed', 'request'], true)) {
            $this->errors[] = "{$path}.seed_mode must be random, fixed, or request.";
        }
        $seed = $response['seed'] ?? null;
        if ($seed !== null && ! is_int($seed)) {
            $this->errors[] = "{$path}.seed must be null or an integer.";
        }
        if (($response['seed_mode'] ?? 'random') === 'fixed' && ! is_int($seed)) {
            $this->errors[] = "{$path}.seed is required when seed_mode is fixed.";
        }
        $locale = $response['locale'] ?? 'en';
        if (! is_string($locale) || ! in_array($locale, config('mock.templates.locales', ['en']), true)) {
            $this->errors[] = "{$path}.locale is not supported.";
        }

        $delay = $response['delay_ms'] ?? null;
        if (! is_int($delay) || $delay < 0 || $delay > config('mock.max_delay_ms', 30000)) {
            $this->errors[] = "{$path}.delay_ms is outside the configured range.";
        }

        $weight = $response['weight'] ?? null;
        if (! is_int($weight) || $weight < 1 || $weight > 1000000) {
            $this->errors[] = "{$path}.weight must be an integer between 1 and 1000000.";
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $known
     */
    private function warnUnknown(array $object, array $known, string $path): void
    {
        foreach (array_diff(array_keys($object), $known) as $field) {
            $this->warnings[] = "{$path}.{$field} is unknown and will be ignored.";
        }
    }
}
