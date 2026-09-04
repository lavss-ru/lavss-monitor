<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vps extends Model
{
    protected $table = 'vps';

    protected $fillable = [
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
            'enabled'         => 'boolean',
            'check_port'      => 'integer',
            'last_checked_at' => 'datetime',
            'last_response_ms'=> 'integer',
        ];
    }
}
