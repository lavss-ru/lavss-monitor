<?php

namespace App\Services;

use App\Models\ProxmoxConnection;
use App\Models\ProxmoxGuest;
use App\Models\ProxmoxNode;

class ProxmoxSummaryService
{
    public function summary(): array
    {
        $monitoring = app(ProxmoxMonitoringService::class);
        $connections = ProxmoxConnection::where('enabled', true)->with(['location', 'nodes', 'guests.node'])->get();
        $ids = $connections->filter(fn ($c) => $c->location->enabled)->pluck('id');
        $onlineIds = $connections->filter(fn ($c) => $monitoring->connectionReady($c))->pluck('id');
        $guests = ProxmoxGuest::whereIn('proxmox_connection_id', $ids)->where('stale', false)->where('template', false)
            ->get(['guest_type', 'status', 'proxmox_connection_id']);
        $result = ['connections' => $ids->count(),
            'nodes' => ProxmoxNode::whereIn('proxmox_connection_id', $ids)->where('stale', false)->count()];
        foreach (['qemu' => 'vm', 'lxc' => 'lxc'] as $type => $key) {
            $group = $guests->where('guest_type', $type);
            $known = $group->whereIn('proxmox_connection_id', $onlineIds);
            $result[$key] = ['total' => $group->count(), 'running' => $known->where('status', 'running')->count(),
                'stopped' => $known->where('status', 'stopped')->count()];
        }

        $result['monitoring'] = [];
        $result['active_incidents'] = [];
        foreach (['connections', 'nodes', 'guests'] as $kind) {
            $result['monitoring'][$kind] = ['online' => 0, 'offline' => 0, 'unknown' => 0];
        }
        foreach ($connections as $connection) {
            if (! $connection->location->enabled) {
                continue;
            }
            foreach (['connections' => [$connection], 'nodes' => $connection->nodes, 'guests' => $connection->guests] as $kind => $monitors) {
                foreach ($monitors as $monitor) {
                    if ($monitoring->enabled($monitor)) {
                        $state = $monitoring->state($monitor, $connection);
                        $result['monitoring'][$kind][$state]++;
                        if ($state === 'offline' && $monitor->incident_confirmed_at !== null) {
                            $type = ['connections' => 'proxmox_connection', 'nodes' => 'proxmox_node', 'guests' => 'proxmox_guest'][$kind];
                            $result['active_incidents'][] = $type.':'.$monitor->id;
                        }
                    }
                }
            }
        }

        return $result;
    }
}
