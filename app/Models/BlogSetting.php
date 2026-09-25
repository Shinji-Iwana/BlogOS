<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WordPressのサイト設定（blog_settings）。BLOGOS_DATABASE.md 5-4。
 */
class BlogSetting extends Model
{
    /**
     * 保存するキー（BLOGOS_DATABASE.md 5-4）。これ以外のキーは保存しない。
     */
    public const KEYS = [
        'title',
        'description',
        'url',
        'home',
        'timezone_string',
        'gmt_offset',
        'date_format',
        'time_format',
        'language',
        'posts_per_page',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'default_category',
        'site_icon',
        'site_logo',
    ];

    protected $fillable = [
        'blog_id',
        'key',
        'value',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BlogSettingHistory::class);
    }
}
