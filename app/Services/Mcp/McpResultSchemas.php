<?php

namespace App\Services\Mcp;

use App\Services\Response\FaultConfigurationValidator;

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
        $response = $object(['id' => $string, 'status' => $int, 'headers' => ['type' => ['object', 'array', 'null']], 'body' => $string, 'body_mode' => $string, 'weight' => $int, 'delay_ms' => $int, 'locale' => $string, 'seed' => $nullable('integer'), 'seed_mode' => $string, 'sequence_order' => $nullable('integer'), 'is_default' => $bool, 'rule_conditions' => $array($generic),
            'fault_enabled' => $bool, 'fault_type' => ['type' => 'string', 'enum' => FaultConfigurationValidator::TYPES],
            'fault_delay_ms_min' => $int, 'fault_delay_ms_max' => $nullable('integer'), 'fault_probability' => $int]);
        $configuration = ['mock_id' => $string, 'name' => $string, 'enabled' => $bool, 'priority' => $int, 'selection_mode' => $string, 'sequence_on_exhaust' => $nullable('string'), 'curl' => $string, 'matching' => $generic, 'collection_id' => $nullable('integer'), 'tags' => $array($int), 'version' => $int, 'revision_reference' => $nullable('integer'), 'responses' => $array($response), 'history_url' => $string, 'limits' => $generic];
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
            'apply_postman_import' => ['run_id' => $string, 'summary' => $generic, 'plan' => $generic, 'items' => $array($object(['mock_id' => $string, 'name' => $string, 'action' => $string, 'response_count' => $int, 'revision_reference' => $nullable('integer'), 'invocation' => $object($urls)]))],
            'preview_postman_import' => ['metadata' => $generic, 'token' => $string, 'digest' => $string, 'mode' => $string, 'replace_responses' => $bool,
                'counts' => $generic, 'items' => $array($generic), 'errors' => $array($string), 'warnings' => $array($string), 'can_apply' => $bool],
            'preview_template' => ['output' => ['type' => ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null']], 'bytes' => $int, 'render_ms' => ['type' => 'number'],
                'synthetic_context' => $bool, 'sample_notice' => $string, 'issues' => $array($object(['severity' => $string, 'code' => $string, 'path' => $string,
                    'line' => $int, 'col' => $int, 'message' => $string, 'suggestion' => $nullable('string')], ['severity', 'code', 'path', 'line', 'col', 'message', 'suggestion']))],
            'peek_next_response' => ['applicable' => $bool, 'selection_mode' => $string, 'sequence_position' => $nullable('integer'), 'response_count' => $int,
                'next_position' => $nullable('integer'), 'position_label' => $nullable('string'), 'reason' => $string, 'response' => [...$response, 'type' => ['object', 'null']]],
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
