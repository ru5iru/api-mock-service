<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneMcpRuntime extends Command
{
    protected $signature = 'mockdeck:mcp-prune';

    protected $description = 'Remove MCP idempotency and encrypted unmatched evidence older than 24 hours';

    public function handle(): int
    {
        $keys = DB::table('mcp_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
        $evidence = DB::table('mcp_unmatched_requests')->where('created_at', '<', now()->subDay())->delete();
        $this->info("Pruned {$keys} idempotency entries and {$evidence} unmatched entries; configurations and audit history preserved.");

        return self::SUCCESS;
    }
}
