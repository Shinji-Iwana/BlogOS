<?php

namespace App\Services\Images;

use App\Models\Image;
use App\Models\Page;
use App\Models\Post;

/**
 * 図の作成の指示文に入れる値（D-32）。PromptBuilder から使う。
 */
class ImagePromptValues
{
    public const FORMATS = ['auto' => 'AIが選ぶ', 'svg' => 'SVG の図', 'illustration' => 'イラスト'];

    /**
     * @param array<string, string|null> $parameters
     * @return array<string, string>
     */
    public function values(?Image $image, Post|Page|null $article, array $parameters): array
    {
        $format = (string) ($parameters['形式の値'] ?? 'auto');
        $lines = [
            '- 図の名前：' . ($image?->title ?? ''),
            '- 依頼の内容：' . str_replace("\n", "\n  ", trim((string) ($image?->description ?? ''))),
            '- 形式：' . ($format === 'auto' ? '指定なし（上の「形式の選び方」に沿って選ぶ）' : (self::FORMATS[$format] ?? $format) . "（{$format} にする）"),
        ];
        if (filled($parameters['補足'] ?? null)) {
            $lines[] = '- 補足：' . str_replace("\n", "\n  ", trim((string) $parameters['補足']));
        }

        $values = ['image_request' => implode("\n", $lines)];

        // 編集案の「画像の依頼」から作った画像は、編集案の本文（AIの改修案・新規記事）を記事として渡す（D-34）
        $draft = $image?->loadMissing('draft')->draft;
        if ($draft !== null) {
            $values['article_info'] = '- タイトル：' . $draft->title_raw . "\n- この図は、本文の [[画像:{$image->id}]] の位置に載せます。";
            $values['article_content'] = (string) $draft->content_raw;

            return $values;
        }

        // 図を載せる記事がなければ、記事の情報は入れない
        if ($article === null) {
            $values['article_info'] = '（なし）';
            $values['article_content'] = '（なし）';
        }

        return $values;
    }
}
