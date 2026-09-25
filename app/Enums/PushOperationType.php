<?php

namespace App\Enums;

/**
 * 反映の操作（wordpress_push_operations.operation）。WORDPRESS_API 22章。
 */
enum PushOperationType: string
{
    case Create = 'create';
    case Update = 'update';
    case StatusChange = 'status_change';
    case Trash = 'trash';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create       => '新規作成',
            self::Update       => '更新',
            self::StatusChange => 'ステータス変更',
            self::Trash        => 'ゴミ箱へ移動',
            self::Delete       => '完全削除',
        };
    }
}
