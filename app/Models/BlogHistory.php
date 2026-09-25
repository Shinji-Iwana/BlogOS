<?php

namespace App\Models;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * blogs の変更履歴（履歴の共通の構造。BLOGOS_DATABASE.md 8-2）。
 */
class BlogHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'blog_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
