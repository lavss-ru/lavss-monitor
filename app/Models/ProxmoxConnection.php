<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProxmoxConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['api_token_secret', 'revision'];

    protected function casts(): array
    {
        return ['monitoring_enabled' => 'boolean', 'failure_started_at' => 'datetime',
            'incident_confirmed_at' => 'datetime', 'incident_notified_at' => 'datetime', 'recovery_pending_at' => 'datetime', 'api_token_secret' => 'encrypted', 'enabled' => 'boolean', 'verify_tls' => 'boolean',
            'port' => 'integer', 'location_id' => 'integer', 'revision' => 'integer',
            'last_checked_at' => 'datetime', 'last_synced_at' => 'datetime', 'last_response_ms' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(ProxmoxNode::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(ProxmoxGuest::class);
    }

    public function unavailableReason(): ?string
    {
        if (! $this->enabled) {
            return 'disabled';
        }
        if (! $this->location->enabled || $this->location->blocksChildren()) {
            return 'location_unavailable';
        }

        return null;
    }

    public function baseUrl(): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$this->host}]" : $this->host;

        return "{$this->scheme}://{$host}:{$this->port}/api2/json";
    }
}
