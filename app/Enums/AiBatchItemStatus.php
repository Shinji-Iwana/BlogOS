<?php

namespace App\Enums;

/**
 * まとめて実行の記事ごとの状態（ai_batch_items.status）。D-25。
 */
enum AiBatchItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    // 実行しなかった（取り消し・費用の上限・改修できない編集案など。理由は message）
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => '待機中',
            self::Running   => '実行中',
            self::Succeeded => '完了',
            self::Failed    => '失敗',
            self::Skipped   => '実行しない',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }
}
