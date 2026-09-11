<?php

namespace App\Console\Commands;

use App\Services\LocalDeviceMonitoringService;
use Illuminate\Console\Command;

class MonitorLocalDevicesCommand extends Command
{
    protected $signature = 'monitor:local-devices';
    protected $description = 'Check enabled local devices using TCP and confirm incidents after two minutes.';

    public function handle(LocalDeviceMonitoringService $monitoring): int
    {
        $result = $monitoring->checkAll();
        $this->line("checked={$result['checked']} errors={$result['errors']}");
        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
