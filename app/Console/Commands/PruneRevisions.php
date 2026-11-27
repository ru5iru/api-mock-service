<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneRevisions extends Command
{
    protected $signature = 'mockdeck:prune-revisions
                            {--keep=50 : Maximum revisions to keep per entity (endpoint or response)}
                            {--dry-run : Report counts without deleting}';

    protected $description = 'Prune old revisions, keeping the most recent N per entity, and remove orphaned revision rows';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $dry = (bool) $this->option('dry-run');

        // Find the threshold revision ID for each entity: keep the latest $keep rows.
        // Rows older than the threshold ID (per entity_type + entity_id group) are pruned.
        $thresholds = DB::table('revisions')
            ->select('entity_type', 'entity_id', DB::raw('MIN(id) AS threshold_id'))
            ->fromSub(
                DB::table('revisions')
                    ->select('entity_type', 'entity_id', 'id',
                        DB::raw('ROW_NUMBER() OVER (PARTITION BY entity_type, entity_id ORDER BY id DESC) AS rn'))
                    ->whereIn('entity_type', ['endpoint', 'response']),
                'ranked'
            )
            ->where('rn', '<=', $keep)
            ->groupBy('entity_type', 'entity_id')
            ->get();

        $pruned = 0;
        foreach ($thresholds as $row) {
            $query = DB::table('revisions')
                ->where('entity_type', $row->entity_type)
                ->where('entity_id', $row->entity_id)
                ->where('id', '<', $row->threshold_id);

            if ($dry) {
                $pruned += $query->count();
            } else {
                $pruned += $query->delete();
            }
        }

        // Orphaned revisions: entity deleted without cascading the revision rows.
        $orphanEndpoints = DB::table('revisions')
            ->where('entity_type', 'endpoint')
            ->whereNotIn('entity_id', DB::table('mock_endpoints')->select('id'));

        $orphanResponses = DB::table('revisions')
            ->where('entity_type', 'response')
            ->whereNotIn('entity_id', DB::table('mock_responses')->select('id'));

        if ($dry) {
            $orphans = $orphanEndpoints->count() + $orphanResponses->count();
        } else {
            $orphans = $orphanEndpoints->delete() + $orphanResponses->delete();
        }

        $prefix = $dry ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$prefix} {$pruned} excess revision(s) (keeping latest {$keep} per entity) and {$orphans} orphaned revision(s).");

        return self::SUCCESS;
    }
}
