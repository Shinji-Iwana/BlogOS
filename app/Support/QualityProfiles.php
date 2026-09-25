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

    /**
     * ブログ別の定義（article-types.md）の記事種類と細分類。
     *
     * 「## 1.」の表を記事種類、「### 1-1.」の表を細分類として読む（値は1列目の `...`、名前は2列目）。
     * 定義がない場合は空の配列を返す（画面では自由入力にする）。
     *
     * @return array{types: array<string, string>, subtypes: array<string, string>}
     */
    public static function articleTypes(?string $profile): array
    {
        $result = ['types' => [], 'subtypes' => []];

        if ($profile === null || ! in_array($profile, self::available(), true)) {
            return $result;
        }

        $path = resource_path("quality/blogs/{$profile}/article-types.md");
        if (! File::exists($path)) {
            return $result;
        }

        $section = null;
        foreach (preg_split('/\r\n|\n/', File::get($path)) as $line) {
            if (preg_match('/^## 1\.\s/', $line)) {
                $section = 'types';
            } elseif (preg_match('/^### 1-1\.\s/', $line)) {
                $section = 'subtypes';
            } elseif (str_starts_with($line, '#')) {
                $section = null;
            } elseif ($section !== null && preg_match('/^\|\s*`([a-z0-9_]+)`\s*\|\s*([^|]+?)\s*\|/', $line, $matches)) {
                $result[$section][$matches[1]] = $matches[2];
            }
        }

        return $result;
    }
}
