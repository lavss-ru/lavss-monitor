<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteAggregateState extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'snapshot' => 'array'];
    }

    /** Acquire first inside every website monitoring/CRUD transaction. */
    public static function lock(): self
    {
        return static::whereKey(1)->lockForUpdate()->firstOrFail();
    }
}
