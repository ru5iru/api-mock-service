<?php

namespace Tests\Feature;

use App\Services\Mcp\McpToolPermissions;
use App\Services\Mcp\McpToolRegistry;
use App\Services\Response\FaultConfigurationValidator;
use Tests\TestCase;

final class McpSchemaReferenceTest extends TestCase
{
    public function test_discovery_permissions_reference_and_annotations_share_one_contract(): void
    {
        $tools = app(McpToolRegistry::class)->all();
        $permissions = array_keys(McpToolPermissions::REQUIRED);
        $names = array_keys($tools);
        sort($permissions);
        sort($names);
        self::assertSame($permissions, $names);
        self::assertCount(16, $tools);
        self::assertSame('read', McpToolPermissions::REQUIRED['preview_template']);
        self::assertTrue($tools['preview_template']['annotations']['readOnlyHint']);
        self::assertTrue($tools['preview_template']['annotations']['idempotentHint']);
        self::assertFalse($tools['preview_template']['annotations']['destructiveHint']);
        self::assertSame(['run_id'], $tools['preview_template']['inputSchema']['required']);
        self::assertSame('read', McpToolPermissions::REQUIRED['peek_next_response']);
        self::assertTrue($tools['peek_next_response']['annotations']['readOnlyHint']);
        self::assertTrue($tools['peek_next_response']['annotations']['idempotentHint']);
        self::assertFalse($tools['peek_next_response']['annotations']['destructiveHint']);
        self::assertSame(['run_id', 'mock_id'], $tools['peek_next_response']['inputSchema']['required']);
        self::assertTrue($tools['explain_match']['annotations']['readOnlyHint']);
        self::assertTrue($tools['finish_test_run']['annotations']['destructiveHint']);
        self::assertTrue($tools['create_mock']['annotations']['idempotentHint']);
        self::assertFalse($tools['reset_test_run']['annotations']['idempotentHint']);
        $this->artisan('mockdeck:mcp-schemas', ['--check' => true])->assertExitCode(0);
    }

    public function test_selection_fields_use_the_same_contract_in_create_validate_and_update(): void
    {
        $tools = app(McpToolRegistry::class)->all();
        $create = (array) $tools['create_mock']['inputSchema']['properties'];
        $validate = (array) $tools['validate_mock']['inputSchema']['properties'];
        $update = (array) $tools['update_mock']['inputSchema']['properties'];
        $creation = (array) $create['configuration']['properties'];
        $proposal = (array) $validate['configuration']['properties'];
        $patch = (array) $update['changes']['properties'];
        $endpoint = (array) $patch['endpoint']['properties'];
        foreach (['selection_mode', 'sequence_on_exhaust'] as $field) {
            self::assertSame($creation[$field], $proposal[$field]);
            self::assertSame($creation[$field], $endpoint[$field]);
        }
        $response = (array) $creation['responses']['items']['properties'];
        $patches = (array) $patch['responses']['properties'];
        foreach (['sequence_order', 'rule_conditions', 'is_default'] as $field) {
            self::assertSame($response[$field], ((array) $patches['add']['items']['properties'])[$field]);
            self::assertSame($response[$field], ((array) $patches['update']['items']['properties'])[$field]);
            self::assertSame($response[$field], ((array) $proposal['responses']['items']['properties'])[$field]);
        }
        self::assertSame($update['changes'], $validate['changes']);
        self::assertSame(['field_type', 'field_name', 'operator', 'priority'], $response['rule_conditions']['items']['required']);
    }

    public function test_fault_fields_extend_the_shared_response_schema_with_only_shipped_types_and_bounds(): void
    {
        $tools = app(McpToolRegistry::class)->all();
        $create = (array) $tools['create_mock']['inputSchema']['properties'];
        $validate = (array) $tools['validate_mock']['inputSchema']['properties'];
        $update = (array) $tools['update_mock']['inputSchema']['properties'];
        $createdResponse = (array) ((array) $create['configuration']['properties'])['responses']['items']['properties'];
        $proposalResponse = (array) ((array) $validate['configuration']['properties'])['responses']['items']['properties'];
        $patch = (array) ((array) $update['changes']['properties'])['responses']['properties'];
        foreach (array_keys(FaultConfigurationValidator::defaults()) as $field) {
            self::assertSame($createdResponse[$field], $proposalResponse[$field]);
            self::assertSame($createdResponse[$field], ((array) $patch['add']['items']['properties'])[$field]);
            self::assertSame($createdResponse[$field], ((array) $patch['update']['items']['properties'])[$field]);
        }
        self::assertSame(FaultConfigurationValidator::TYPES, $createdResponse['fault_type']['enum']);
        self::assertNotContains('connection_reset', $createdResponse['fault_type']['enum']);
        self::assertSame(FaultConfigurationValidator::MAX_DELAY_MS, $createdResponse['fault_delay_ms_min']['maximum']);
        self::assertSame(['integer', 'null'], $createdResponse['fault_delay_ms_max']['type']);
        foreach (['create_mock', 'update_mock', 'get_mock'] as $tool) {
            $data = (array) ((array) $tools[$tool]['outputSchema']['properties'])['data']['properties'];
            $response = (array) $data['responses']['items']['properties'];
            self::assertSame(FaultConfigurationValidator::TYPES, $response['fault_type']['enum']);
            foreach (array_keys(FaultConfigurationValidator::defaults()) as $field) {
                self::assertArrayHasKey($field, $response);
            }
        }
    }
}
