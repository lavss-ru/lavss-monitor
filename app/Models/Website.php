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
    ];

    protected function casts(): array
    {
        return [
            'enabled'          => 'boolean',
            'last_checked_at'  => 'datetime',
            'last_response_ms' => 'integer',
            'last_http_status' => 'integer',
        ];
    }
}
