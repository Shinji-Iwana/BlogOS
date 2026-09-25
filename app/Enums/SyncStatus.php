<?php

namespace App\Enums;

/**
 * 同期の状態（sync_runs.status・sync_run_resources.status）。BLOGOS_DATABASE.md 10-1。
 */
enum SyncStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';

    // 一部のリソースが失敗した（失敗しなかったリソースの結果は残す）
    case Partial = 'partial';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running   => '実行中',
            self::Succeeded => '成功',
            self::Partial   => '一部失敗',
            self::Failed    => '失敗',
        };
    }
}
