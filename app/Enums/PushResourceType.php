<?php

namespace App\Enums;

/**
 * 反映の対象の種類（wordpress_push_operations.resource_type）。
 */
enum PushResourceType: string
{
    case Post = 'post';
    case Page = 'page';
    case Category = 'category';
    case Tag = 'tag';
    case Media = 'media';

    public function label(): string
    {
        return match ($this) {
            self::Post     => '投稿',
            self::Page     => '固定ページ',
            self::Category => 'カテゴリ',
            self::Tag      => 'タグ',
            self::Media    => 'メディア',
        };
    }
}
