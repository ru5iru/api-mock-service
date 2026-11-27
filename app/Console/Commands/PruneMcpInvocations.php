<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneMcpInvocations extends Command
{
    protected $signature = 'mockdeck:prune-mcp-invocations
                            {--days=90 : Delete invocations older than this many days}
                            {--dry-run : Report count without deleting}';

    protected $description = 'Delete mcp_invocations rows older than the configured retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        $query = DB::table('mcp_invocations')->where('created_at', '<', $cutoff);

        if ($dry) {
            $count = $query->count();
            $this->info("[dry-run] Would delete {$count} MCP invocation(s) older than {$days} day(s).");
        } else {
            $count = $query->delete();
            $this->info("Deleted {$count} MCP invocation(s) older than {$days} day(s).");
        }

        return self::SUCCESS;
    }
}
