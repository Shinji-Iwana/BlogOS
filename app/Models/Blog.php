<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blog extends Model
{
    protected $fillable = [
        'name',
        'description',
        'url',
        'home',
        'gmt_offset',
        'timezone',
        'is_selected',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BlogHistory::class);
    }
}
