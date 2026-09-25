<?php

namespace App\Enums;

/**
 * 反映記録の状態（wordpress_push_operations.state）。ARCHITECTURE 13-5、WORDPRESS_API 24章。
 */
enum PushState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case WpSucceeded = 'wp_succeeded';
    case Completed = 'completed';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /**
     * 編集案を再反映できないようにロックする状態（WORDPRESS_API 24-1）
     *
     * @return array<int, self>
     */
    public static function locking(): array
    {
        return [self::Sent, self::Unknown, self::WpSucceeded];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending     => '送信前',
            self::Sent        => '送信中',
            self::WpSucceeded => 'WordPressで成功（DBの更新待ち）',
            self::Completed   => '完了',
            self::Failed      => '失敗',
            self::Unknown     => '結果が不明',
        };
    }
}
