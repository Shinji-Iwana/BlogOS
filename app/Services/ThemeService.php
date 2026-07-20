<?php

namespace App\Services;

class ThemeService
{
    /**
     * 現在使用中のテーマ名を取得
     */
    public static function current()
    {
        return config('blogos.theme');
    }

    /**
     * CSSファイルのパスを取得
     */
    public static function css()
    {
        return "/themes/" . self::current() . "/css/style.css";
    }
}
