<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    protected $table = 'websites';

    protected $fillable = [
        'name',
        'url',
        'type',
        'enabled',
        'description',
        'status',
        'last_checked_at',
        'last_response_ms',
        'last_http_status',
        'failure_started_at',
        'incident_confirmed_at',
        'incident_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'failure_started_at' => 'datetime',
            'incident_confirmed_at' => 'datetime',
            'incident_notified_at' => 'datetime',
            'enabled'          => 'boolean',
            'last_checked_at'  => 'datetime',
            'last_response_ms' => 'integer',
            'last_http_status' => 'integer',
        ];
    }
}
