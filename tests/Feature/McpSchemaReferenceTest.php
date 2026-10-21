<?php

namespace Tests\Feature;

use App\Services\Mcp\McpToolPermissions;
use App\Services\Mcp\McpToolRegistry;
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
        self::assertCount(15, $tools);
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
}
