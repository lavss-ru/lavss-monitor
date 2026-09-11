<?php

use App\Models\LocalDevice;
use App\Models\Location;
use App\Services\LocalDeviceHealthCheckService;
use App\Services\LocalDeviceMonitoringService;

function localLocation(array $attributes = []): Location
{
    return Location::create(array_merge(['name' => 'Тестовая площадка', 'connection_type' => 'wireguard', 'enabled' => true], $attributes));
}

function localDevice(array $attributes = []): LocalDevice
{
    return LocalDevice::create(array_merge(['location_id' => $attributes['location_id'] ?? localLocation()->id,
        'name' => 'Тестовый PVE', 'host' => '192.0.2.10', 'check_port' => 8006, 'type' => 'proxmox',
        'enabled' => true, 'status' => 'unknown'], $attributes));
}

function localPayload(LocalDevice $device, array $attributes = []): array
{
    return array_merge($device->only(['location_id', 'name', 'host', 'check_port', 'type', 'enabled', 'description']), $attributes);
}

function localFakeCheck(string $status): void
{
    config(['testing.local_status' => $status]);
    test()->mock(LocalDeviceHealthCheckService::class)->shouldReceive('check')->andReturnUsing(function (LocalDevice $device) {
        $status = config('testing.local_status');
        $result = ['status' => $status, 'response_ms' => $status === 'online' ? 7 : null];
        $device->update(['status' => $status, 'last_response_ms' => $result['response_ms'], 'last_checked_at' => now()]);
        return $result;
    });
}

function localMonitor(LocalDevice $device, bool $diagnostic = false): array
{
    // Recreate service and reload model on every call to exercise process boundaries.
    return app(LocalDeviceMonitoringService::class)->monitor($device->fresh(), $diagnostic);
}

function localHttpFake(array $responses): void
{
    // Replace the factory: repeated Http::fake calls otherwise accumulate URL callbacks.
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake($responses);
}
