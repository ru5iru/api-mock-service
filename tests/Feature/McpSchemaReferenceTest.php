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
        self::assertCount(14, $tools);
        self::assertTrue($tools['explain_match']['annotations']['readOnlyHint']);
        self::assertTrue($tools['finish_test_run']['annotations']['destructiveHint']);
        self::assertTrue($tools['create_mock']['annotations']['idempotentHint']);
        self::assertFalse($tools['reset_test_run']['annotations']['idempotentHint']);
        $this->artisan('mockdeck:mcp-schemas', ['--check' => true])->assertExitCode(0);
    }
}
