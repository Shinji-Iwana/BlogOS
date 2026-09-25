<?php

namespace App\Models;

use App\Enums\GoogleService;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Googleのデータの取得の実行記録（BLOGOS_DATABASE.md 12-2）。
 */
class GoogleFetchRun extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service'     => GoogleService::class,
            'trigger'     => SyncTrigger::class,
            'status'      => SyncStatus::class,
            'date_from'   => 'date',
            'date_to'     => 'date',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 実行記録は同期の記録と同じく1年で削除する（取得したデータそのものは無期限。D-21-05）
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subYear());
    }
}
