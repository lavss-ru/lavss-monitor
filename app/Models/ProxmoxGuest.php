<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxmoxGuest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monitoring_enabled' => 'boolean', 'failure_started_at' => 'datetime',
            'incident_confirmed_at' => 'datetime', 'incident_notified_at' => 'datetime', 'recovery_pending_at' => 'datetime', 'stale' => 'boolean', 'template' => 'boolean', 'vmid' => 'integer', 'cpu_usage' => 'float',
            'memory_used' => 'integer', 'memory_total' => 'integer', 'disk_used' => 'integer',
            'disk_total' => 'integer', 'uptime_seconds' => 'integer', 'max_cpu' => 'integer', 'last_seen_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProxmoxConnection::class, 'proxmox_connection_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(ProxmoxNode::class, 'proxmox_node_id');
    }
}
