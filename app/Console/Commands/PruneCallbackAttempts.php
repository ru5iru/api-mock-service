<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneCallbackAttempts extends Command
{
    protected $signature = 'mockdeck:prune-callback-attempts
                            {--days=90 : Delete attempts older than this many days}
                            {--dry-run : Report count without deleting}';

    protected $description = 'Delete callback_attempts rows older than the configured retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        $query = DB::table('callback_attempts')->where('created_at', '<', $cutoff);

        if ($dry) {
            $count = $query->count();
            $this->info("[dry-run] Would delete {$count} callback attempt(s) older than {$days} day(s).");
        } else {
            $count = $query->delete();
            $this->info("Deleted {$count} callback attempt(s) older than {$days} day(s).");
        }

        return self::SUCCESS;
    }
}
