<?php

namespace App\Console\Commands;

use App\Models\Vps;
use App\Services\VpsMonitoringService;
use Illuminate\Console\Command;
use Throwable;

class MonitorVpsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'monitor:vps';

    /**
     * The console command description.
     */
    protected $description = 'Run TCP health-check for all enabled VPS servers and record status-change events.';

    public function __construct(private VpsMonitoringService $monitoring)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $vpsList = Vps::where('enabled', true)->get();

        $checked = 0;
        $online  = 0;
        $offline = 0;
        $changed = 0;
        $errors  = 0;

        foreach ($vpsList as $vps) {
            try {
                $result = $this->monitoring->monitor($vps);

                $checked++;

                if ($result['status'] === 'online') {
                    $online++;
                } else {
                    $offline++;
                }

                if ($result['event_created']) {
                    $changed++;
                    $transition = "{$result['previous_status']} → {$result['status']}";
                    $this->line("  <comment>[CHANGED]</comment> {$vps->name}: {$transition}");
                } else {
                    $this->line("  <info>[OK]</info>      {$vps->name}: {$result['status']}");
                }
            } catch (Throwable $e) {
                $errors++;
                $this->line("  <error>[ERROR]</error>   {$vps->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Summary:</> checked=%d  online=%d  offline=%d  changed=%d  errors=%d',
            $checked,
            $online,
            $offline,
            $changed,
            $errors,
        ));

        return self::SUCCESS;
    }
}
