<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProxmoxNode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stale' => 'boolean', 'cpu_usage' => 'float', 'memory_used' => 'integer',
            'memory_total' => 'integer', 'uptime_seconds' => 'integer', 'max_cpu' => 'integer', 'last_seen_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProxmoxConnection::class, 'proxmox_connection_id');
    }

    public function guests(): HasMany
    {
        return $this->hasMany(ProxmoxGuest::class);
    }
}
