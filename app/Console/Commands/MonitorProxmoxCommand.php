<?php

namespace App\Console\Commands;

use App\Models\ProxmoxConnection;
use App\Services\ProxmoxSyncService;
use Illuminate\Console\Command;

class MonitorProxmoxCommand extends Command
{
    protected $signature = 'monitor:proxmox';

    protected $description = 'Read-only Proxmox inventory sync';

    public function handle(ProxmoxSyncService $service): int
    {
        $checked = $errors = $skipped = 0;
        foreach (ProxmoxConnection::where('enabled', true)->orderBy('id')->lazyById() as $connection) {
            try {
                $result = $service->run($connection);
                $result['skipped'] ? $skipped++ : $checked++;
                if (! $result['skipped'] && $result['code'] !== null) {
                    $errors++;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }
        $this->info("Proxmox: checked={$checked}, errors={$errors}, skipped={$skipped}");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
