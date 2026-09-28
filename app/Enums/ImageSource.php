<?php

namespace App\Enums;

/**
 * 画像の作り方（images.source）。D-32。
 */
enum ImageSource: string
{
    // AI が作った SVG を、ブラウザで PNG にした
    case AiSvg = 'ai_svg';

    // 画像モデルが作った
    case AiImage = 'ai_image';

    // 人がアップロードした（ChatGPT 等で作った画像を含む）
    case Upload = 'upload';

    public function label(): string
    {
        return match ($this) {
            self::AiSvg   => 'AI（SVG）',
            self::AiImage => 'AI（画像モデル）',
            self::Upload  => 'アップロード',
        };
    }
}
