<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ブログごとのAIの設定（条件による自動の再評価。D-25）。
 */
class BlogAiSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'auto_reevaluation_enabled' => 'boolean',
            'auto_revision_enabled'     => 'boolean',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
