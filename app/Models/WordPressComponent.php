<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WordPress 本体・プラグイン・テーマのバージョンと、WordPress.org の最新のバージョン（D-38）。
 */
class WordPressComponent extends Model
{
    protected $table = 'wordpress_components';

    protected $guarded = ['id'];

    public const TYPE_LABELS = ['core' => 'WordPress 本体', 'plugin' => 'プラグイン', 'theme' => 'テーマ'];

    protected function casts(): array
    {
        return [
            'update_available' => 'boolean',
            'checked_at'       => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /**
     * 人に知らせる必要があるか（更新がある・公開停止・無効のまま残っている）
     */
    public function needsAttention(): bool
    {
        return $this->update_available || $this->wporg_state === 'closed' || ($this->type === 'plugin' && $this->status === 'inactive');
    }
}
