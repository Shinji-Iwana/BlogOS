<?php

namespace App\Models;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * blog_settings の変更履歴（履歴の共通の構造。BLOGOS_DATABASE.md 8-2）。
 */
class BlogSettingHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'blog_id',
        'blog_setting_id',
        'change_set_id',
        'field',
        'old_value',
        'new_value',
        'source',
        'sync_run_id',
        'wordpress_push_operation_id',
        'user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'source'     => ChangeSource::class,
            'changed_at' => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(BlogSetting::class, 'blog_setting_id');
    }
}
