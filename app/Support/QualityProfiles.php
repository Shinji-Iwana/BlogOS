<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * 品質基準のブログ別の定義（resources/quality/blogs/{識別子}）の一覧。D-06-10、D-13-02。
 */
class QualityProfiles
{
    /**
     * @return array<int, string> 利用できる識別子（フォルダ名）
     */
    public static function available(): array
    {
        $directory = resource_path('quality/blogs');

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::directories($directory))
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->values()
            ->all();
    }
}
