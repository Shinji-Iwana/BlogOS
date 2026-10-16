<?php

namespace App\Services\Articles;

use App\Models\Blog;
use App\Models\Media;

/**
 * 記事の本文の HTML の、PageSpeed Insights（ユーザー補助・パフォーマンス）で引っかかる書き方を、仕上げで直す（D-84-04）。
 * ルールは品質基準（html-rules.md 1-1 見出し・3-9 画像・3-14 表）にあり、古い記事と、AI が守らなかった場合に直す。
 *
 * 1. 画像の width・height：メディアライブラリの画像は、メディアの幅・高さ（縮小版は URL の -幅x高さ）を入れる。分からない画像は知らせる
 * 2. 見出しの順番：レベルが飛んでいたら詰める（h1 → h2、h2 の次の h4 → h3）
 * 3. 表の見出し：th が1つもない表は、1行目を thead と th にして知らせる（1列目が項目名の表もあるため、人が確かめる）
 * 4. リストの入れ子：ul・ol の中に直接ある ul・ol を、直前の li の中に入れる
 * 5. 名前のないリンク：文字も alt もないリンクは、直さずに知らせる
 *
 * 遅延読み込み（loading）・先に読み込む指定（fetchpriority）は、WordPress が表示のときに付けるため、ここでは付けない。
 */
class ArticleHtmlMarkupFixer
{
    /**
     * @return array{content: string, notes: list<string>}
     */
    public function fix(Blog $blog, string $content): array
    {
        $notes = [];

        $content = $this->sizeImages($blog, $content, $notes);
        $content = $this->fixHeadingOrder($content, $notes);
        $content = $this->addTableHeaders($content, $notes);
        $content = $this->nestLists($content, $notes);
        $this->checkEmptyLinks($content, $notes);

        return ['content' => $content, 'notes' => $notes];
    }

    /**
     * @param list<string> $notes
     */
    protected function sizeImages(Blog $blog, string $content, array &$notes): string
    {
        $unknown = [];
        $media = null;

        $content = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($blog, &$unknown, &$media) {
            $tag = $m[0];
            if (preg_match('/\swidth\s*=/i', $tag) && preg_match('/\sheight\s*=/i', $tag)) {
                return $tag;
            }
            $src = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $s) ? html_entity_decode($s[2], ENT_QUOTES) : '';
            $media ??= $this->mediaIndex($blog);
            $size = $this->imageSize($src, preg_match('/\bwp-image-(\d+)\b/', $tag, $id) ? (int) $id[1] : null, $media);
            if ($size === null) {
                $unknown[] = $src;

                return $tag;
            }

            $tag = preg_replace('/\s(?:width|height)\s*=\s*(["\']).*?\1/i', '', $tag);

            return preg_replace('/^<img\b/i', '<img width="' . $size[0] . '" height="' . $size[1] . '"', $tag);
        }, $content) ?? $content;

        if ($unknown !== []) {
            $notes[] = '幅・高さの分からない画像があります（メディアライブラリにない画像のため）：' . implode('、', array_unique($unknown))
                . '。メディアライブラリの画像に置き換えてください（幅・高さがないと、PageSpeed Insights の「画像要素で width と height が明示的に指定されていない」に出ます。html-rules.md 3-9）';
        }

        return $content;
    }

    /**
     * メディアの WordPress の ID と、ファイルのパスから、幅・高さを引く表
     *
     * @return array{ids: array<int, array{0: int, 1: int}>, paths: array<string, array{0: int, 1: int}>}
     */
    protected function mediaIndex(Blog $blog): array
    {
        $index = ['ids' => [], 'paths' => []];
        foreach (Media::where('blog_id', $blog->id)->whereNotNull('width')->whereNotNull('height')->get(['wordpress_id', 'source_url', 'width', 'height']) as $media) {
            $size = [(int) $media->width, (int) $media->height];
            $index['ids'][(int) $media->wordpress_id] = $size;
            if (filled($media->source_url)) {
                $index['paths'][(string) parse_url($media->source_url, PHP_URL_PATH)] = $size;
            }
        }

        return $index;
    }

    /**
     * @param array{ids: array<int, array{0: int, 1: int}>, paths: array<string, array{0: int, 1: int}>} $media
     * @return array{0: int, 1: int}|null
     */
    protected function imageSize(string $src, ?int $wordpressId, array $media): ?array
    {
        $path = (string) parse_url($src, PHP_URL_PATH);

        // 縮小版（-300x200.jpg）は、元の画像がメディアライブラリにあれば、URL の大きさを使う
        if (preg_match('/-(\d+)x(\d+)(\.[a-z0-9]+)$/i', $path, $resized)) {
            $original = substr($path, 0, -strlen($resized[0])) . $resized[3];
            if (($wordpressId !== null && isset($media['ids'][$wordpressId])) || isset($media['paths'][$original])) {
                return [(int) $resized[1], (int) $resized[2]];
            }
        }

        return $media['paths'][$path] ?? ($wordpressId !== null ? $media['ids'][$wordpressId] ?? null : null);
    }

    /**
     * @param list<string> $notes
     */
    protected function fixHeadingOrder(string $content, array &$notes): string
    {
        // 記事タイトル（h1）はテーマが出すため、本文の見出しは h2 から始まる
        $previous = 1;
        $fixed = [];

        $content = preg_replace_callback('/<h([1-6])(\b[^>]*)>(.*?)<\/h\1>/isu', function ($m) use (&$previous, &$fixed) {
            $level = (int) $m[1];
            $allowed = max(2, min($level, $previous + 1));
            $previous = $allowed;
            if ($allowed === $level) {
                return $m[0];
            }
            $fixed[] = '「' . trim(strip_tags($m[3])) . "」h{$level} → h{$allowed}";

            return "<h{$allowed}{$m[2]}>{$m[3]}</h{$allowed}>";
        }, $content) ?? $content;

        if ($fixed !== []) {
            $notes[] = '見出しのレベルが飛んでいたため、詰めました（html-rules.md 1-1。PageSpeed Insights の「見出し要素は降順になっていません」）：' . implode('、', $fixed);
        }

        return $content;
    }

    /**
     * @param list<string> $notes
     */
    protected function addTableHeaders(string $content, array &$notes): string
    {
        $count = 0;

        $content = preg_replace_callback('/(<table\b[^>]*>)((?:(?!<table\b).)*?)(<\/table>)/isu', function ($m) use (&$count) {
            $body = $m[2];
            if (preg_match('/<th\b/i', $body) || preg_match_all('/<tr\b/i', $body) < 2 || ! preg_match('/<tr\b[^>]*>.*?<\/tr>/isu', $body, $first, PREG_OFFSET_CAPTURE)) {
                return $m[0];
            }
            $count++;
            $row = preg_replace(['/<td\b/i', '/<\/td>/i'], ['<th', '</th>'], $first[0][0]);
            $rest = substr_replace($body, '', $first[0][1], strlen($first[0][0]));
            // 1行目を抜いた後に、空になった thead・tbody は外す
            $rest = preg_replace('/<(thead|tbody)\b[^>]*>\s*<\/\1>\s*/i', '', $rest);

            return $m[1] . "\n<thead>\n" . $row . "\n</thead>" . $rest . $m[3];
        }, $content) ?? $content;

        if ($count > 0) {
            $notes[] = "見出しのセル（th）のない表 {$count}件の、1行目を見出し（thead・th）にしました（html-rules.md 3-14。PageSpeed Insights の「<td> 要素にテーブル ヘッダーがありません」）。1列目が項目名の表など、1行目が見出しでない場合は直してください";
        }

        return $content;
    }

    /**
     * @param list<string> $notes
     */
    protected function nestLists(string $content, array &$notes): string
    {
        $count = 0;
        // 直前の </li> の後ろにある入れ子のリスト（中にさらにリストがないもの）を、その li の中に入れる
        do {
            $content = preg_replace('/<\/li>(\s*)(<(ul|ol)\b[^>]*>(?:(?!<\/?(?:ul|ol)\b).)*<\/\3>)/isu', '$1$2</li>', $content, -1, $replaced) ?? $content;
            $count += $replaced;
        } while ($replaced > 0);

        if ($count > 0) {
            $notes[] = "li の外にあった入れ子のリスト {$count}件を、直前の li の中に入れました（PageSpeed Insights の「リストには、<li> 要素…以外も含まれています」）";
        }

        return $content;
    }

    /**
     * @param list<string> $notes
     */
    protected function checkEmptyLinks(string $content, array &$notes): void
    {
        $empty = [];
        preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/isu', $content, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            $named = trim(html_entity_decode(strip_tags($link[2]), ENT_QUOTES)) !== ''
                || preg_match('/<img\b[^>]*\salt\s*=\s*(["\'])\s*[^"\'\s][^"\']*\1/i', $link[2])
                || preg_match('/\saria-label\s*=\s*(["\'])\s*\S/i', $link[1]);
            if (! $named) {
                $empty[] = preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $link[1], $href) ? $href[2] : '(href なし)';
            }
        }

        if ($empty !== []) {
            $notes[] = '文字も画像の alt もないリンクがあります：' . implode('、', array_unique($empty)) . '。リンクの文字か、画像の alt を書いてください（PageSpeed Insights の「リンクに識別可能な名前が付いていません」）';
        }
    }
}
