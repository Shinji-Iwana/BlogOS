<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * お知らせ（D-74）。作り方と更新は App\Services\Notices\NoticeService。
 */
class Notice extends Model
{
    protected $fillable = [
        'blog_id',
        'kind',
        'level',
        'message',
        'url',
        'link_label',
        'signature',
        'occurred_at',
        'changed_at',
        'resolved_at',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at'  => 'datetime',
            'changed_at'   => 'datetime',
            'resolved_at'  => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * 今も続いている（変動も解消もしていない）お知らせ
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('changed_at')->whereNull('resolved_at');
    }

    /**
     * ヘッダーの件数・自動で開くポップアップの対象：今も続いていて、未確認のお知らせ
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->current()->whereNull('confirmed_at');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
