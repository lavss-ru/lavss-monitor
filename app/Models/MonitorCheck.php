<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only check samples; deleted monitors intentionally have no cascading relation. */
class MonitorCheck extends Model
{
    public const TYPES = ['vps', 'website', 'local_device', 'location'];

    public const ORIGINS = ['scheduled', 'manual', 'manual_batch'];

    public const STATUSES = ['online', 'offline', 'unknown'];

    public $timestamps = false;

    protected $fillable = [
        'monitor_type', 'monitor_id', 'origin', 'status', 'checked_at', 'response_ms', 'http_status',
    ];

    protected function casts(): array
    {
        return ['monitor_id' => 'integer', 'checked_at' => 'immutable_datetime',
            'response_ms' => 'integer', 'http_status' => 'integer'];
    }
}
