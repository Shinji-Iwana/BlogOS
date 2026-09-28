<?php

namespace App\Models;

use App\Enums\AiCreditEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OpenAI の残高の記録（人が OpenAI の画面で見た残高と、課金した額）。D-31-04。
 */
class AiCreditEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type'              => AiCreditEntryType::class,
            'amount'            => 'float',
            'estimated_balance' => 'float',
            'occurred_at'       => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
