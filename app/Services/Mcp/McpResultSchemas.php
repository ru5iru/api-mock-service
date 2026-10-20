<?php

namespace App\Services\Mcp;

/** Output contracts are shared with discovery and the checked-in JSON reference. */
final class McpResultSchemas
{
    public function forTool(string $tool): array
    {
        $string = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $bool = ['type' => 'boolean'];
        $object = fn (array $properties, array $required = []) => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
        $array = fn (array $items) => ['type' => 'array', 'items' => $items];
        $nullable = fn (string $type) => ['type' => [$type, 'null']];
        $generic = ['type' => 'object'];
        $response = $object(['id' => $string, 'status' => $int, 'headers' => ['type' => ['object', 'array', 'null']], 'body' => $string, 'body_mode' => $string, 'weight' => $int, 'delay_ms' => $int, 'locale' => $string, 'seed' => $nullable('integer'), 'seed_mode' => $string]);
        $configuration = ['mock_id' => $string, 'name' => $string, 'enabled' => $bool, 'priority' => $int, 'curl' => $string, 'matching' => $generic, 'collection_id' => $nullable('integer'), 'tags' => $array($int), 'version' => $int, 'revision_reference' => $nullable('integer'), 'responses' => $array($response), 'history_url' => $string, 'limits' => $generic];
        $urls = ['preferred' => $string, 'header' => $object(['url' => $string, 'headers' => $generic, 'curl' => $string]), 'path_prefix' => $object(['url' => $string, 'curl' => $string]), 'origin_note' => $string];
        $assertion = ['passed' => $bool, 'actual_count' => $nullable('integer'), 'message' => $string, 'verdict' => $string, 'scope' => $string, 'count_bounds' => $object(['minimum' => $int, 'maximum' => $int]), 'window' => $generic];
        $fields = match ($tool) {
            'start_test_run' => ['run_id' => $string, 'owner_id' => $int, 'label' => $string, 'status' => $string, 'environment' => $object(['id' => $int, 'name' => $string]), 'preferred' => $string, 'routing' => $generic],
            'list_test_runs' => ['runs' => $object(['data' => $array($object(['public_id' => $string, 'label' => $string, 'status' => $string, 'created_at' => $string])), 'current_page' => $int, 'per_page' => $int, 'total' => $int])],
            'create_mock', 'update_mock' => [...$configuration, 'invocation' => $object($urls), 'matcher_summary' => $generic, 'warnings' => $array($string)],
            'list_mocks' => ['items' => $array($object(['mock_id' => $string, 'name' => $string, 'method' => $string, 'path' => $string, 'enabled' => $bool])), 'page' => $int, 'per_page' => $int, 'total' => $int],
            'get_mock' => $configuration,
            'validate_mock' => ['valid' => $bool, 'errors' => $array($generic), 'warnings' => $array($string)],
            'get_mock_url' => $urls,
            'explain_match' => ['selected' => $nullable('string'), 'candidates' => $array($object(['mock_id' => $string, 'name' => $string, 'priority' => $int, 'selected' => $bool, 'enabled' => $bool, 'path_captures' => ['type' => ['object', 'array']], 'differences' => $array($object(['field' => $string, 'name' => $string, 'excluded' => $bool, 'matches' => $nullable('boolean')])), 'excluded_query_params' => $array($string), 'headers' => $array($generic)])), 'candidate_window' => $generic, 'note' => $string],
            'get_unmatched_requests' => ['items' => $array($object(['id' => $string, 'created_at' => $string, 'complete' => $bool, 'request' => $object(['method' => $string, 'url' => $string, 'request_id' => $string])])), 'page' => $int, 'total_retained' => $int, 'window' => $generic],
            'get_calls' => ['total_match_count' => $int, 'last_matched_at' => $nullable('string'), 'sequence_position' => $nullable('integer'), 'recent_calls' => $array($generic), 'window' => $generic],
            'assert_calls' => $assertion,
            'reset_test_run' => ['run_id' => $string, 'reset' => $bool, 'state_rows_reset' => $int, 'configuration_preserved' => $bool],
            'finish_test_run' => ['run_id' => $string, 'status' => $string, 'cleanup' => $string, 'mock_count' => $int, 'total_match_count' => $int, 'finished_at' => $string],
        };

        return $object(['ok' => $bool, 'data' => $object($fields), 'error' => $object(['code' => ['type' => 'string', 'enum' => array_column(McpErrorCode::cases(), 'value')], 'message' => $string, 'details' => $generic], ['code', 'message']), 'correlation_id' => ['type' => 'string', 'format' => 'uuid']], ['ok', 'correlation_id']);
    }
}
