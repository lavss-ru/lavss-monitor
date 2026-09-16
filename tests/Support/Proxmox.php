<?php

use App\Models\ProxmoxConnection;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/LocationConnectivity.php';

function pveConnection(array $attributes = []): ProxmoxConnection
{
    return ProxmoxConnection::create(array_replace([
        'location_id' => localLocation()->id, 'name' => 'Test PVE', 'host' => 'pve.example.test', 'port' => 8006,
        'scheme' => 'https', 'verify_tls' => true, 'api_user' => 'monitor@pve', 'api_token_id' => 'inventory',
        'api_token_secret' => 'synthetic-test-secret', 'enabled' => true,
    ], $attributes))->fresh();
}

function pveResources(): array
{
    return [
        ['type' => 'node', 'node' => 'pve1', 'status' => 'online', 'cpu' => 0.25, 'mem' => 1024, 'maxmem' => 4096, 'maxcpu' => 4, 'uptime' => 12345],
        ['type' => 'node', 'node' => 'pve2', 'status' => 'offline'],
        ['type' => 'qemu', 'node' => 'pve1', 'vmid' => 100, 'name' => 'VM test', 'status' => 'running', 'cpu' => 0.5,
            'mem' => 512, 'maxmem' => 2048, 'maxcpu' => 2, 'disk' => 0, 'maxdisk' => 8192, 'uptime' => 100],
        ['type' => 'lxc', 'node' => 'pve2', 'vmid' => 101, 'name' => 'LXC test', 'status' => 'stopped', 'template' => 0],
        ['type' => 'storage', 'storage' => 'local'],
    ];
}

function pveFake(?array $resources = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/version' => Http::response(['data' => ['version' => '8.4.1']]),
        '*/nodes' => Http::response(['data' => [['node' => 'pve1'], ['node' => 'pve2']]]),
        '*/cluster/resources' => Http::response(['data' => $resources ?? pveResources()]),
    ]);
}

function pvePayload(ProxmoxConnection $connection, array $changes = []): array
{
    return array_replace($connection->only(['name', 'location_id', 'host', 'port', 'scheme', 'verify_tls', 'api_user', 'api_token_id', 'enabled']),
        ['api_token_secret' => ''], $changes);
}
