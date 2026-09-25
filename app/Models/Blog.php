<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BlogOSが管理するブログ（blogs）。BLOGOS_DATABASE.md 5-2。
 *
 * BlogOS側で管理する情報だけを持つ。サイト名などWordPressの設定は blog_settings に、
 * 認証情報は blog_credentials に分けて持つ。
 */
class Blog extends Model
{
    protected $fillable = [
        'home',
        'display_name',
        'quality_profile',
        'is_selected',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BlogHistory::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BlogSetting::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(BlogCredential::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
