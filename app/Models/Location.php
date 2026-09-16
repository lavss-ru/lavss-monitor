<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    public const CONNECTION_TYPES = ['local', 'wireguard', 'vpn', 'other'];

    public const RESET_STATE = LocalDevice::RESET_STATE + [
        'wireguard_diagnostic_state' => 'unknown', 'wireguard_last_handshake_at' => null,
        'wireguard_rx_bytes' => null, 'wireguard_tx_bytes' => null,
    ];

    protected $fillable = [
        'name', 'description', 'connection_type', 'enabled', 'monitoring_enabled', 'probe_type',
        'probe_host', 'probe_port', 'wireguard_interface', 'wireguard_peer_public_key',
        'status', 'last_checked_at', 'last_response_ms', 'failure_started_at', 'incident_confirmed_at',
        'incident_notified_at', 'recovery_pending_at', 'wireguard_diagnostic_state',
        'wireguard_last_handshake_at', 'wireguard_rx_bytes', 'wireguard_tx_bytes',
    ];

    public function monitored(): bool
    {
        return $this->enabled && $this->monitoring_enabled && $this->probe_host !== null && $this->probe_port !== null;
    }

    public function blocksChildren(): bool
    {
        return $this->monitored() && ($this->status === 'unknown'
            || ($this->status === 'offline' && $this->incident_confirmed_at !== null));
    }

    public function endpoint(): string
    {
        $host = filter_var($this->probe_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$this->probe_host}]" : $this->probe_host;

        return "{$host}:{$this->probe_port}";
    }

    public function resolveWarnings(): void
    {
        Event::where('type', 'location')->where('source_id', $this->id)
            ->where('severity', 'warning')->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'monitoring_enabled' => 'boolean', 'probe_port' => 'integer',
            'last_response_ms' => 'integer', 'last_checked_at' => 'datetime',
            'failure_started_at' => 'datetime', 'incident_confirmed_at' => 'datetime',
            'incident_notified_at' => 'datetime', 'recovery_pending_at' => 'datetime',
            'wireguard_last_handshake_at' => 'datetime', 'wireguard_rx_bytes' => 'integer', 'wireguard_tx_bytes' => 'integer',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(LocalDevice::class);
    }

    public function proxmoxConnections(): HasMany
    {
        return $this->hasMany(ProxmoxConnection::class);
    }
}
