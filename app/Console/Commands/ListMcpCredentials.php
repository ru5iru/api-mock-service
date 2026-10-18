<?php

namespace App\Console\Commands;

use App\Models\McpCredential;
use App\Services\Mcp\McpPermission;
use Illuminate\Console\Command;

final class ListMcpCredentials extends Command
{
    protected $signature = 'mockdeck:mcp-credential:list';

    protected $description = 'List MCP credential metadata without tokens or hashes';

    public function handle(): int
    {
        $rows = McpCredential::query()->select(['id', 'name', 'can_read', 'can_write', 'can_verify', 'can_cleanup', 'last_used_at', 'revoked_at'])->orderBy('id')->get()
            ->map(static fn (McpCredential $credential): array => [
                $credential->id,
                $credential->name,
                implode(', ', array_map(static fn (McpPermission $permission): string => $permission->value, array_filter(McpPermission::cases(), static fn (McpPermission $permission): bool => (bool) $credential->getAttribute('can_'.$permission->value)))),
                $credential->last_used_at?->toIso8601String() ?? 'never',
                $credential->revoked_at?->toIso8601String() ?? 'active',
            ])->all();
        $this->table(['ID', 'Name', 'Permissions', 'Last used', 'Revoked at / status'], $rows);

        return self::SUCCESS;
    }
}
