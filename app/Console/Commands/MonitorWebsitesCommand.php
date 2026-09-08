<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\WebsiteMonitoringService;
use Illuminate\Console\Command;
use Throwable;

class MonitorWebsitesCommand extends Command
{
    protected $signature = 'monitor:websites';

    protected $description = 'Run HTTP health-checks for enabled websites and record status-change events.';

    public function __construct(private WebsiteMonitoringService $monitoring)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $checked = $online = $offline = $changed = $errors = 0;

        foreach (Website::where('enabled', true)->orderBy('id')->get() as $website) {
            try {
                $result = $this->monitoring->monitor($website);
                $checked++;
                if ($result['status'] === 'online') {
                    $online++;
                } else {
                    $offline++;
                }

                if ($result['event_created']) {
                    $changed++;
                    $previous = $result['previous_status'] ?? 'unknown';
                    $this->line("[CHANGED] {$website->name}: {$previous} → {$result['status']}");
                } else {
                    $this->line("[OK] {$website->name}: {$result['status']}");
                }
            } catch (Throwable $e) {
                report($e);
                $errors++;
                $this->line("[ERROR] {$website->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line("Summary: checked={$checked}  online={$online}  offline={$offline}  changed={$changed}  errors={$errors}");

        return self::SUCCESS;
    }
}
