<?php

namespace App\Console\Commands;

use App\Services\LocalDeviceMonitoringService;
use Illuminate\Console\Command;

class MonitorLocalDevicesCommand extends Command
{
    protected $signature = 'monitor:local-devices';

    protected $description = 'Probe locations before local devices and apply configured incident confirmation delays.';

    public function handle(LocalDeviceMonitoringService $monitoring): int
    {
        $result = $monitoring->checkAll(origin: 'scheduled');
        $this->line("checked={$result['checked']} errors={$result['errors']}");

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
