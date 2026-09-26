<?php

namespace App\Enums;

/**
 * AI実行記録の状態（ai_generations.status）。
 */
enum AiGenerationStatus: string
{
    case WaitingOutput = 'waiting_output';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::WaitingOutput => '回答の貼り付け待ち',
            self::Running       => '実行中',
            self::Succeeded     => '完了',
            self::Failed        => '失敗',
            self::Cancelled     => '取り消し',
        };
    }
}
