<?php

namespace App\Services\Mcp;

use InvalidArgumentException;

/** Contract for future handlers, not a tool registry or transport. Unknown tools fail closed. */
final class McpToolPermissions
{
    public const REQUIRED = [
        'start_test_run' => 'write',
        'create_mock' => 'write',
        'update_mock' => 'write',
        'list_mocks' => 'read',
        'get_mock' => 'read',
        'validate_mock' => 'read',
        'get_mock_url' => 'read',
        'explain_match' => 'read',
        'get_unmatched_requests' => 'verify',
        'get_calls' => 'verify',
        'assert_calls' => 'verify',
        'reset_test_run' => 'cleanup',
        'finish_test_run' => 'cleanup',
    ];

    public static function required(string $tool): McpPermission
    {
        return McpPermission::from(self::REQUIRED[$tool] ?? throw new InvalidArgumentException('Unknown MCP tool.'));
    }
}
