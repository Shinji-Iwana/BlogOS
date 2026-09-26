<?php

namespace App\Enums;

/**
 * まとめて実行の状態（ai_batches.status）。D-25。
 */
enum AiBatchStatus: string
{
    case Running = 'running';
    case Completed = 'completed';

    // 人が取り消した
    case Cancelled = 'cancelled';

    // 費用の上限などで、途中で止めた
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Running   => '実行中',
            self::Completed => '完了',
            self::Cancelled => '取り消し',
            self::Stopped   => '途中で停止',
        };
    }
}
