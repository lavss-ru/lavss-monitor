<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalDevice extends Model
{
    public const TYPES = ['proxmox', 'linux_server', 'windows_server', 'router', 'vm', 'network_device', 'other'];

    public const RESET_STATE = [
        'status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
        'failure_started_at' => null, 'incident_confirmed_at' => null,
        'incident_notified_at' => null, 'recovery_pending_at' => null,
    ];

    protected $fillable = [
        'location_id', 'name', 'type', 'host', 'check_port', 'enabled', 'description',
        'status', 'last_checked_at', 'last_response_ms', 'failure_started_at',
        'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'location_id' => 'integer', 'check_port' => 'integer',
            'last_response_ms' => 'integer', 'last_checked_at' => 'datetime',
            'failure_started_at' => 'datetime', 'incident_confirmed_at' => 'datetime',
            'incident_notified_at' => 'datetime', 'recovery_pending_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeMonitored(Builder $query): void
    {
        $query->where('enabled', true)->whereHas('location', fn (Builder $q) => $q->where('enabled', true));
    }

    public function endpoint(): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$this->host}]" : $this->host;
        return "{$host}:{$this->check_port}";
    }

    public function resolveWarnings(): void
    {
        Event::where('type', 'local_device')->where('source_id', $this->id)
            ->where('severity', 'warning')->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }
}
