<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    public const CONNECTION_TYPES = ['local', 'wireguard', 'vpn', 'other'];

    protected $fillable = ['name', 'description', 'connection_type', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(LocalDevice::class);
    }
}
