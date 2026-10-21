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
use App\Services\Mutations\ResponsePoolEntry;
use App\Services\Mutations\ResponsePoolInput;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;

/** Convert public MCP data into the same complete DTOs used by the dashboard. */
final class McpMockConfiguration
{
    public const ENDPOINT = ['name', 'enabled', 'priority', 'curl', 'request', 'matching', 'collection_id', 'tags', 'selection_mode', 'sequence_on_exhaust'];

    public const RESPONSE = ['id', 'status', 'headers', 'body', 'body_mode', 'locale', 'seed_mode', 'seed', 'delay_ms', 'weight', 'sequence_order', 'rule_conditions', 'is_default'];

    /** Project an explicit patch into a final pool, without saving or changing runtime state. */
    public function changes(array $changes, MockEndpoint $current): array
    {
        $patch = $changes['responses'] ?? [];
        $ids = [...array_column($patch['update'] ?? [], 'id'), ...($patch['remove'] ?? [])];
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['responses' => 'Each response can be changed only once per operation.']);
        }
        $rows = $current->responses()->get()->mapWithKeys(fn ($response) => [$response->uuid => ['id' => $response->uuid]])->all();
        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new McpDomainException(McpErrorCode::NotFound, 'Response does not belong to this mock.');
            }
        }
        foreach ($patch['remove'] ?? [] as $id) {
            unset($rows[$id]);
        }
        foreach ($patch['update'] ?? [] as $data) {
            $rows[$data['id']] = $data;
        }

        return [...($changes['endpoint'] ?? []), 'responses' => [...array_values($rows), ...($patch['add'] ?? [])]];
    }

    public function pool(array $configuration, ?MockEndpoint $current = null): ResponsePoolInput
    {
        $mode = $configuration['selection_mode'] ?? $current?->selection_mode ?? 'weighted';
        $exhaust = $configuration['sequence_on_exhaust'] ?? $current?->sequence_on_exhaust ?? 'repeat_last';
        if ($mode !== 'sequence') {
            // Retain pre-extension Weighted metadata, including a no-op patch's
            // history version; the value is inactive outside Sequence mode.
            $exhaust = $current?->selection_mode === $mode ? $current->sequence_on_exhaust : ($current === null && $mode === 'weighted' ? 'repeat_last' : null);
        }
        if (array_key_exists('sequence_on_exhaust', $configuration) && $mode !== 'sequence') {
            throw ValidationException::withMessages(['sequence_on_exhaust' => 'On exhaust is only available for Sequence selection.']);
        }
        $existing = $current?->responses()->with('rules')->get()->keyBy('uuid');
        $rows = $configuration['responses'] ?? $existing?->map(fn ($response) => ['id' => $response->uuid])->values()->all() ?? [];
        if (count($rows) > 50) {
            throw ValidationException::withMessages(['responses' => 'P0 supports at most 50 responses per mock.']);
        }
        $entries = [];
        $ids = [];
        foreach ($rows as $index => $data) {
            $response = isset($data['id']) ? $existing?->get($data['id']) : null;
            if (isset($data['id']) && $response === null) {
                throw new McpDomainException(McpErrorCode::NotFound, 'Response does not belong to this mock.');
            }
            if ($response !== null && in_array($response->id, $ids, true)) {
                throw ValidationException::withMessages(['responses' => 'Each response can be changed only once per operation.']);
            }
            $ids[] = $response?->id;
            foreach (['sequence_order' => 'sequence', 'rule_conditions' => 'rule', 'is_default' => 'rule'] as $field => $requiredMode) {
                if (array_key_exists($field, $data) && $mode !== $requiredMode) {
                    throw ValidationException::withMessages(["responses.$index.$field" => "$field is only available for $requiredMode selection."]);
                }
            }
            $entries[] = new ResponsePoolEntry($response?->id, $this->response($data, $response),
                $mode === 'sequence' ? ($data['sequence_order'] ?? ($current?->selection_mode === 'sequence' ? $response?->sequence_order : null) ?? $index) : null,
                $mode === 'rule' && ($data['is_default'] ?? ($current?->selection_mode === 'rule' && $response?->is_default)),
                $mode === 'rule' ? ($data['rule_conditions'] ?? ($current?->selection_mode === 'rule' ? $response?->rules->map(fn ($rule) => $rule->only(['field_type', 'field_name', 'operator', 'value', 'priority']))->all() : null) ?? []) : [],
                $response === null || array_diff(array_keys($data), ['id', 'sequence_order', 'rule_conditions', 'is_default']) !== []);
        }

        return new ResponsePoolInput($mode, $exhaust, $entries);
    }

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
