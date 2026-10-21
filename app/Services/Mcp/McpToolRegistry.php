<?php

namespace App\Services\Mcp;

use App\Services\Response\FaultConfigurationValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One bounded public contract drives discovery, validation and the reference guide. */
final class McpToolRegistry
{
    public function all(): array
    {
        $string = fn (int $max = 255) => ['type' => 'string', 'maxLength' => $max];
        $enum = fn (array $values) => ['type' => 'string', 'enum' => $values];
        $integer = fn (int $min, int $max) => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max];
        $object = fn (array $properties, array $required = []) => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false];
        $array = fn (array $items, int $max = 50, int $min = 0) => ['type' => 'array', 'items' => $items, 'maxItems' => $max, 'minItems' => $min];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $headers = ['type' => 'object', 'additionalProperties' => $string(4096), 'maxProperties' => 64];
        $conditionFields = ['field_type' => $enum(['header', 'query', 'body_json_path']), 'field_name' => $string(), 'operator' => $enum(['equals', 'contains', 'regex', 'exists']), 'value' => $string(4096)];
        $ruleCondition = $object([...$conditionFields, 'value' => ['type' => ['string', 'null'], 'maxLength' => 4096], 'priority' => $integer(0, 2147483647)], ['field_type', 'field_name', 'operator', 'priority']);
        $faultFields = ['fault_enabled' => ['type' => 'boolean'],
            'fault_type' => [...$enum(FaultConfigurationValidator::TYPES), 'description' => 'Existing primary-response fault. Timeout approximates a timeout with bounded delay; connection_reset is unsupported.'],
            'fault_delay_ms_min' => $integer(0, FaultConfigurationValidator::MAX_DELAY_MS),
            'fault_delay_ms_max' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => FaultConfigurationValidator::MAX_DELAY_MS],
            'fault_probability' => $integer(0, 100)];
        $responseFields = ['status' => $integer(100, 599), 'headers' => $headers, 'body' => $string(65536), 'body_mode' => $enum(['static', 'template']), 'locale' => $string(32), 'seed_mode' => $enum(['random', 'fixed', 'request']), 'seed' => $integer(-2147483648, 2147483647), 'delay_ms' => $integer(0, 30000), 'weight' => $integer(1, 1000000), 'sequence_order' => $integer(0, 49), 'rule_conditions' => $array($ruleCondition, 32), 'is_default' => ['type' => 'boolean'], ...$faultFields];
        $response = $object($responseFields);
        $endpoint = ['selection_mode' => $enum(['weighted', 'sequence', 'rule']), 'sequence_on_exhaust' => $enum(['repeat_last', 'loop', 'not_found']), 'name' => $string(), 'enabled' => ['type' => 'boolean'], 'priority' => $integer(-1000, 1000), 'curl' => $string(131072),
            'request' => $object(['method' => $enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']), 'url' => $string(8192), 'headers' => $headers, 'body' => $string(65536)], ['url']),
            'matching' => $object(['exclude_cookies' => ['type' => 'boolean'], 'exclude_auth' => ['type' => 'boolean'], 'exclude_headers' => ['type' => 'boolean'], 'excluded_query_params' => $array($string(), 100), 'excluded_headers' => $array($string(), 100), 'path_pattern_enabled' => ['type' => 'boolean']]),
            'collection_id' => $integer(1, 2147483647), 'tags' => $array($integer(1, 2147483647), 50)];
        $config = $object([...$endpoint, 'responses' => $array($response, 50, 1)]);
        $proposal = $object([...$endpoint, 'responses' => $array($object(['id' => $uuid, ...$responseFields]), 50, 1)]);
        $changes = $object(['endpoint' => $object($endpoint), 'responses' => $object(['add' => $array($response), 'update' => $array($object(['id' => $uuid, ...$responseFields], ['id'])), 'remove' => $array($uuid)])]);
        $run = ['run_id' => $uuid];
        $mock = [...$run, 'mock_id' => $uuid];
        $pagination = ['page' => $integer(1, 10000), 'per_page' => $integer(1, 50)];
        $preference = $enum(['header', 'path-prefix']);
        $condition = $object($conditionFields, ['field_type', 'field_name', 'operator']);
        $definitions = [
            'start_test_run' => ['Create an isolated run owned by this credential, bound to an Environment name (defaults to the active environment without changing it). Returns instructions for both header and prefix routing.', $object(['label' => $string(), 'environment' => $string(), 'preferred' => $preference], ['label'])],
            'list_test_runs' => ['Recover your own active or finished runs after a client restart. Does not expose another credential or ordinary dashboard mocks.', $object(['status' => $enum(['active', 'finished']), ...$pagination])],
            'create_mock' => ['Atomically create one run-owned request and 1–50 responses. Omit selection fields for Weighted; Sequence uses zero-based order and an exhaustion policy; Rule requires exactly one fallback. Provide curl OR structured request. Idempotency key required; same-run canonical duplicates return CONFLICT with the existing ID. No scripts execute.', $object([...$run, 'configuration' => $config, 'idempotency_key' => $string(128)], ['run_id', 'configuration', 'idempotency_key'])],
            'list_mocks' => ['Paginate mocks in your own run only. Optional name, method and path filters. Ordinary dashboard mocks are outside P0 access.', $object([...$run, 'name' => $string(), 'method' => $string(16), 'path' => $string(1024), ...$pagination], ['run_id'])],
            'get_mock' => ['Read full run mock configuration, response summaries and current configuration version. Secrets are redacted and text is bounded; never echo redactions back as updates.', $object($mock, ['run_id', 'mock_id'])],
            'validate_mock' => ['Validate proposed configuration without saving or advancing runtime state. Supply configuration OR the same changes patch used by update_mock (requires run_id/mock_id). Validates the final response pool with the shared dashboard selection validator.', $object([...$mock, 'configuration' => $proposal, 'changes' => $changes])],
            'update_mock' => ['Patch endpoint fields and add/update/remove individual responses atomically. Omitted fields are preserved. Supply the version returned by get_mock; stale versions return CONFLICT. Supports Weighted, Sequence and Rule selection; validates the final pool atomically, including fallback swaps.', $object([...$mock, 'expected_version' => $integer(0, 2147483647), 'changes' => $changes], ['run_id', 'mock_id', 'expected_version', 'changes'])],
            'get_mock_url' => ['Return both callable routing forms and POSIX example curls. Header routing keeps the path; prefix routing needs no injected header. Replace the origin with the reachable service address from your application.', $object([...$mock, 'preferred' => $preference], ['run_id', 'mock_id'])],
            'peek_next_response' => ['Read the next configured Sequence response and current run/environment cursor without consuming it or creating call state. Honors Repeat last, Loop and Not found. Other modes explicitly return not applicable; templates and callbacks are not executed.', $object($mock, ['run_id', 'mock_id'])],
            'explain_match' => ['Diagnose a curl OR retained unmatched log_entry_id using the real scoped matcher, with field comparisons and exclusions, without selecting a response, counters or callbacks. Sensitive values are never returned.', $object([...$run, 'curl' => $string(131072), 'log_entry_id' => $uuid], ['run_id'])],
            'get_unmatched_requests' => ['Read recent owned-run unmatched request IDs for explain_match. Retention is at most 20 entries and 24 hours, not complete history. Payload evidence stays encrypted server-side.', $object([...$run, ...$pagination], ['run_id'])],
            'get_calls' => ['Read exact since-reset counters and at most 20 retained call digests for one owned mock. Every result states how much evidence is absent; this does not change counters.', $object([...$mock, 'limit' => $integer(1, 20)], ['run_id', 'mock_id'])],
            'assert_calls' => ['Assert exact unfiltered counts or existing header/query/JSON predicate grammar. Returns ASSERTION_FAILED for definite failure, INCONCLUSIVE_EVIDENCE when retained evidence cannot establish the result. Always includes a CI-readable message.', $object([...$mock, 'expect_count' => $integer(0, 2147483647), 'comparator' => $enum(['equals', 'at_least', 'at_most']), 'matching' => $array($condition, 32)], ['run_id', 'mock_id', 'expect_count', 'comparator'])],
            'reset_test_run' => ['Reset only this active owned run’s counters, sequence positions and verification evidence. Preserve all mock configuration; record the reset in run history. Requires cleanup permission.', $object($run, ['run_id'])],
            'finish_test_run' => ['Finish your run with delete_mocks or keep_mocks. Deletion affects only this run. Repeating finish returns the original final summary and never performs additional cleanup. Requires cleanup permission.', $object([...$run, 'cleanup' => $enum(['delete_mocks', 'keep_mocks'])], ['run_id', 'cleanup'])],
        ];
        $readOnly = ['list_test_runs', 'list_mocks', 'get_mock', 'validate_mock', 'get_mock_url', 'peek_next_response', 'explain_match', 'get_unmatched_requests', 'get_calls', 'assert_calls'];

        return collect($definitions)->mapWithKeys(fn ($item, $name) => [$name => ['name' => $name, 'description' => $item[0], 'inputSchema' => $item[1],
            'outputSchema' => app(McpResultSchemas::class)->forTool($name),
            'annotations' => ['readOnlyHint' => in_array($name, $readOnly, true), 'destructiveHint' => in_array($name, ['finish_test_run', 'update_mock', 'reset_test_run'], true), 'idempotentHint' => in_array($name, [...$readOnly, 'create_mock', 'finish_test_run'], true), 'openWorldHint' => false]]])->all();
    }

    public function validate(string $tool, array $input): void
    {
        $schema = $this->all()[$tool]['inputSchema'] ?? null;
        if ($schema === null) {
            throw new McpDomainException(McpErrorCode::NotFound, 'Unknown tool.');
        }
        $this->check($schema, $input, 'arguments');
        if (isset($input['mock_id']) && ! isset($input['run_id'])) {
            $this->invalid('run_id', 'A mock reference requires its owned run ID.');
        }
        if ($tool === 'explain_match' && (isset($input['curl']) === isset($input['log_entry_id']))) {
            $this->invalid('request', 'Provide exactly one of curl or log_entry_id.');
        }
        if ($tool === 'validate_mock') {
            if (isset($input['configuration']) === isset($input['changes'])) {
                $this->invalid('configuration', 'Provide exactly one of configuration or changes.');
            }
            if (isset($input['changes']) && ! isset($input['mock_id'])) {
                $this->invalid('mock_id', 'Validating changes requires an owned run and mock reference.');
            }
        }
        if (in_array($tool, ['create_mock', 'validate_mock'], true) && ! isset($input['mock_id']) && ! isset($input['configuration']['curl']) && ! isset($input['configuration']['request'])) {
            $this->invalid('configuration.request', 'Provide curl or structured request.');
        }
    }

    private function check(array $schema, mixed $value, string $path): void
    {
        $type = $schema['type'];
        if (is_array($type)) {
            if ($value === null && in_array('null', $type, true)) {
                return;
            }
            $type = $type[0];
        }
        $valid = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)), 'array' => is_array($value) && array_is_list($value), 'integer' => is_int($value), 'boolean' => is_bool($value), 'string' => is_string($value)
        };
        if (! $valid) {
            $this->invalid($path, 'Expected '.$type.'.');
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->invalid($path, 'Unsupported value.');
        }
        if ($type === 'string' && ((isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) || (($schema['format'] ?? '') === 'uuid' && ! Str::isUuid($value)))) {
            $this->invalid($path, 'Invalid or oversized string.');
        }
        if ($type === 'integer' && ($value < $schema['minimum'] || $value > $schema['maximum'])) {
            $this->invalid($path, 'Number outside supported range.');
        }
        if ($type === 'array') {
            if (count($value) < $schema['minItems'] || count($value) > $schema['maxItems']) {
                $this->invalid($path, 'Array outside supported bounds.');
            }
            foreach ($value as $key => $item) {
                $this->check($schema['items'], $item, $path.'.'.$key);
            }
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $this->invalid($path.'.'.$key, 'This field is required.');
                }
            }
            if (count($value) > ($schema['maxProperties'] ?? 100)) {
                $this->invalid($path, 'Too many fields.');
            }
            $props = (array) ($schema['properties'] ?? []);
            foreach ($value as $key => $item) {
                $child = $props[$key] ?? ($schema['additionalProperties'] ?? false);
                if ($child === false) {
                    $this->invalid($path, 'Unknown field.');
                }
                if (is_array($child)) {
                    $this->check($child, $item, $path.'.'.$key);
                }
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
