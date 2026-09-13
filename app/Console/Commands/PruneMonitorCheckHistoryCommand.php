<?php

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use Illuminate\Console\Command;

class PruneMonitorCheckHistoryCommand extends Command
{
    public const RETENTION_DAYS = 30;
    public const BATCH_SIZE = 2000;

    protected $signature = 'monitor:prune-check-history';
    protected $description = 'Delete check samples older than 30 rolling days in bounded batches.';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;
        do {
            $ids = MonitorCheck::where('checked_at', '<', $cutoff)->orderBy('checked_at')
                ->limit(self::BATCH_SIZE)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += MonitorCheck::whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());
        $this->line("Deleted {$deleted} check history rows.");
        return self::SUCCESS;
    }
}
