<?php

namespace App\Models;

use App\Enums\AiBatchStatus;
use App\Enums\AiBatchTarget;
use App\Enums\AiBatchTrigger;
use App\Enums\AiMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BlogOSのAI機能のまとめて実行（D-25）。記事ごとの実行は ai_batch_items。
 */
class AiBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose'           => AiMode::class,
            'trigger'           => AiBatchTrigger::class,
            'target'            => AiBatchTarget::class,
            'target_parameters' => 'array',
            'follow_up'         => 'array',
            'status'            => AiBatchStatus::class,
            'completed_at'      => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiBatchItem::class);
    }

    /**
     * 続けて行った記事改修の、元の品質診断（D-26）
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_batch_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_batch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
