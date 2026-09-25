<?php

namespace App\Enums;

/**
 * 同期の実行契機（sync_runs.trigger）。BLOGOS_DATABASE.md 10-1、D-15-07。
 */
enum SyncTrigger: string
{
    // ブログ登録時の初回取得
    case Initial = 'initial';

    // 毎日の定期同期（cron）
    case Scheduled = 'scheduled';

    // 画面の「今すぐ同期」
    case Manual = 'manual';

    // 回復処理だけを単独で手動実行した場合
    case Recovery = 'recovery';

    public function label(): string
    {
        return match ($this) {
            self::Initial   => '初回取得',
            self::Scheduled => '毎日の同期',
            self::Manual    => '手動同期',
            self::Recovery  => '回復処理',
        };
    }
}
