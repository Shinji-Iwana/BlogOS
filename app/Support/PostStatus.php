<?php

namespace App\Support;

/**
 * WordPress の記事の状態（status）の日本語の名前（記事の画面。D-82-05）
 */
class PostStatus
{
    public const LABELS = [
        'publish'    => '公開済み',
        'future'     => '予約投稿',
        'draft'      => '下書き',
        'pending'    => 'レビュー待ち',
        'private'    => '非公開',
        'trash'      => 'ゴミ箱',
        'auto-draft' => '自動下書き',
        'inherit'    => '親に従う',
    ];

    /**
     * 日本語の名前（知らない状態は、そのまま返す）
     */
    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }
}
