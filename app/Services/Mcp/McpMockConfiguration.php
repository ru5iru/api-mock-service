<?php

namespace App\Services\Mcp;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Curl\CurlFormatter;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointRequestService;
use App\Services\Mutations\ResponseInput;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;

/** Convert public MCP data into the same complete DTOs used by the dashboard. */
final class McpMockConfiguration
{
    public const ENDPOINT = ['name', 'enabled', 'priority', 'curl', 'request', 'matching', 'collection_id', 'tags'];

    public const RESPONSE = ['id', 'status', 'headers', 'body', 'body_mode', 'locale', 'seed_mode', 'seed', 'delay_ms', 'weight'];

    public function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['configuration' => 'Unknown configuration fields: '.implode(', ', array_diff(array_keys($data), $allowed))]);
        }
    }

    public function endpoint(array $data, ?MockEndpoint $current = null): EndpointInput
    {
        $this->keys($data, self::ENDPOINT);
        $matching = $data['matching'] ?? [];
        $map = ['exclude_cookies' => 'excludeCookies', 'exclude_auth' => 'excludeAuth', 'exclude_headers' => 'excludeHeaders',
            'excluded_query_params' => 'excludedQueryParams', 'excluded_headers' => 'excludedHeaderNames', 'path_pattern_enabled' => 'pathPatternEnabled'];
        $this->keys($matching, array_keys($map));
        $values = [];
        if ($current !== null) {
            foreach ((new ReflectionClass(EndpointInput::class))->getConstructor()->getParameters() as $parameter) {
                $field = $parameter->getName();
                $values[$field] = match ($field) {
                    'rawCurl' => $current->raw_curl, 'collectionId' => (string) ($current->collection_id ?? ''),
                    'tagIds' => $current->tags()->pluck('tags.id')->all(),
                    'environmentOverrides' => $current->environmentOverrides->mapWithKeys(fn ($env) => [$env->id => $env->pivot->enabled ? '1' : '0'])->all(),
                    'excludedHeaderNames' => $current->excluded_headers ?? [],
                    'name' => (string) $current->name,
                    default => $current->getAttribute(Str::snake($field)) ?? $parameter->getDefaultValue(),
                };
            }
        }
        foreach (['name' => 'name', 'enabled' => 'enabled', 'priority' => 'priority', 'collection_id' => 'collectionId', 'tags' => 'tagIds'] as $key => $field) {
            if (array_key_exists($key, $data)) {
                $values[$field] = $key === 'collection_id' ? (string) ($data[$key] ?? '') : $data[$key];
            }
        }
        foreach ($matching as $key => $value) {
            $values[$map[$key]] = $value;
        }
        if (isset($data['curl'], $data['request'])) {
            throw ValidationException::withMessages(['request' => 'Provide curl OR structured request, not both.']);
        }
        if (isset($data['curl'])) {
            $values['rawCurl'] = $data['curl'];
        }
        if (isset($data['request'])) {
            $request = $data['request'];
            $this->keys($request, ['method', 'url', 'headers', 'body']);
            $headers = [];
            foreach ($request['headers'] ?? [] as $name => $value) {
                $headers[] = ['name' => $name, 'value' => (string) $value];
            }
            $values['rawCurl'] = app(CurlFormatter::class)->format(new ParsedCurl($request['method'] ?? 'GET', $request['url'] ?? '/', $headers, $request['body'] ?? ''));
        }

        return new EndpointInput(...$values);
    }

    public function response(array $data, ?MockResponse $current = null): ResponseInput
    {
        $this->keys($data, self::RESPONSE);
        $values = [];
        if ($current !== null) {
            foreach ((new ReflectionClass(ResponseInput::class))->getConstructor()->getParameters() as $parameter) {
                $name = $parameter->getName();
                $values[$name] = match ($name) {
                    'headersJson' => json_encode((object) ($current->headers ?? [])),
                    'callbackHeadersJson' => json_encode((object) ($current->callback_headers ?? [])),
                    'callbackSecretSet' => $current->callback_signing_secret !== null,
                    default => $current->getAttribute(Str::snake($name)) ?? $parameter->getDefaultValue(),
                };
            }
        }
        $map = ['status' => 'statusCode', 'body' => 'body', 'body_mode' => 'bodyMode', 'seed_mode' => 'seedMode',
            'seed' => 'seed', 'locale' => 'locale', 'weight' => 'weight', 'delay_ms' => 'delayMs'];
        foreach ($map as $key => $field) {
            if (array_key_exists($key, $data)) {
                $values[$field] = $data[$key];
            }
        }
        if (isset($data['headers'])) {
            $values['headersJson'] = json_encode((object) $data['headers'], JSON_THROW_ON_ERROR);
        }
        if (($values['bodyMode'] ?? 'static') === 'template' && array_key_exists('body', $data)) {
            $values['template'] = $data['body'];
        }
        $values['editorView'] = 'json';

        return new ResponseInput(...$values);
    }

    public function warnings(EndpointInput $input): array
    {
        $service = app(EndpointRequestService::class);
        $parsed = app(CurlParser::class)->parse($input->rawCurl);
        $names = [...collect($service->analyzeHeaders($input, $parsed))->where('sensitive', true)->pluck('name')->all(), ...collect($service->queryParameters($input, $parsed))->where('sensitive', true)->pluck('key')->all()];

        return [...$service->curlWarnings($input->rawCurl), ...($names === [] ? [] : ['Possible credentials detected: '.implode(', ', array_unique($names)).'. Values are redacted from MCP results; provide them only through your trusted client configuration.'])];
    }
}
