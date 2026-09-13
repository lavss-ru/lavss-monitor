<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vps extends Model
{
    protected $table = 'vps';

    protected $fillable = [
        'failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at',
        'name',
        'hostname',
        'ip_address',
        'description',
        'enabled',
        'status',
        'check_port',
        'last_checked_at',
        'last_response_ms',
    ];

    protected function casts(): array
    {
        return [
            'failure_started_at' => 'datetime', 'incident_confirmed_at' => 'datetime',
            'incident_notified_at' => 'datetime', 'recovery_pending_at' => 'datetime',
            'enabled' => 'boolean',
            'check_port' => 'integer',
            'last_checked_at' => 'datetime',
            'last_response_ms' => 'integer',
        ];
    }
}
