<?php

namespace App\Services;

use App\Models\ProxmoxConnection;
use App\Models\ProxmoxGuest;
use App\Models\ProxmoxNode;

class ProxmoxSummaryService
{
    public function summary(): array
    {
        $connections = ProxmoxConnection::where('enabled', true)->with('location')->get();
        $ids = $connections->filter(fn ($c) => $c->location->enabled)->pluck('id');
        $onlineIds = $connections->filter(fn ($c) => ! $c->unavailableReason() && $c->status === 'online')->pluck('id');
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

        return $result;
    }
}
