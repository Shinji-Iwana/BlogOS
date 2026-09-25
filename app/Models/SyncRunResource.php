<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 同期1回 × リソース種別ごとの結果（sync_run_resources）。BLOGOS_DATABASE.md 10-2。
 */
class SyncRunResource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'      => SyncStatus::class,
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }
}
