<?php

namespace App\Models;

use App\Enums\AffiliateLinkCheckResult;
use App\Enums\AffiliateProgramStatus;
use App\Enums\MaterialKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アフィリエイトのプログラム（ASPで提携する広告）。D-33-08。
 *
 * もしもアフィリエイトは広告（p_id）ごと、Udemy・Amazon・楽天はサービスごとに1件とする。
 */
class AffiliateProgram extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'            => AffiliateProgramStatus::class,
            'material_kind'     => MaterialKind::class,
            'status_changed_on' => 'date',
            'check_result'      => AffiliateLinkCheckResult::class,
            'checked_at'        => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function isUsable(): bool
    {
        return $this->status->usable();
    }
}
