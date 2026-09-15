<?php

use App\Models\Location;
use App\Services\LocationHealthCheckService;
use App\Services\LocationMonitoringService;

require_once __DIR__.'/LocalInfrastructure.php';

function probeLocation(array $attributes = []): Location
{
    return Location::create(array_merge(['name' => 'Remote test', 'enabled' => true, 'monitoring_enabled' => true,
        'connection_type' => 'wireguard', 'probe_type' => 'tcp', 'probe_host' => '192.0.2.10', 'probe_port' => 8006], $attributes));
}

function locationFakeCheck(string $status): void
{
    config(['testing.location_status' => $status]);
    test()->mock(LocationHealthCheckService::class)->shouldReceive('check')->andReturnUsing(function (Location $location) {
        $status = config('testing.location_status');
        if ($status === 'error') {
            throw new RuntimeException('Synthetic location probe error');
        }
        $result = ['status' => $status, 'response_ms' => $status === 'online' ? 7 : null];
        $location->update(['status' => $status, 'last_response_ms' => $result['response_ms'], 'last_checked_at' => now()]);

        return $result;
    });
}

function locationMonitor(Location $location, string $origin = 'scheduled'): array
{
    return app(LocationMonitoringService::class)->monitor($location->fresh(), origin: $origin);
}

function locationPayload(Location $location, array $overrides = []): array
{
    return array_replace($location->only(['name', 'description', 'enabled', 'connection_type', 'monitoring_enabled',
        'probe_type', 'probe_host', 'probe_port', 'wireguard_interface', 'wireguard_peer_public_key']), $overrides);
}
