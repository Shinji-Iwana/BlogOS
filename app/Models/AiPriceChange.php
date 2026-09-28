<?php

namespace App\Models;

use App\Enums\AiPriceChangeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 料金の変更（公式のページとの差）。D-31-03。
 */
class AiPriceChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_value'  => 'float',
            'new_value'  => 'float',
            'status'     => AiPriceChangeStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isIncrease(): bool
    {
        return $this->old_value === null || $this->new_value > $this->old_value;
    }
}
