<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * 公式のページとの料金の照合の記録（D-31-03）。1年を過ぎたら削除する。
 */
class AiPriceCheck extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subYear());
    }
}
