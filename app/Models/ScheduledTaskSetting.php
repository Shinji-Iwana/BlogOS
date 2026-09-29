<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 定期実行の時刻・有効かの設定（D-44）。行がない定期実行は、App\Support\ScheduledTasks の既定で動く。
 */
class ScheduledTaskSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
