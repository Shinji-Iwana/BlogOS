<?php

namespace App\Services\Articles;

use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;

/**
 * タイトル・メタディスクリプションの確認（共通基準 writing.md 3-1・3-2、si-note article-types.md 1-2。D-36）。
 *
 * AIを使わない機械的な確認。文字数、先頭の文字数の中のメインキーワード、書き出しの【…】、ほかの記事との重複を見る。
 */
class ArticleTitleChecker
{
    /**
     * @var array<int, array{titles: array<string, list<string>>, metas: array<string, list<string>>}> ブログごとの、公開中の記事のタイトル・説明
     */
    protected array $existing = [];

    /**
     * @param Post|Page|null $self 確認する記事（重複の確認から除く）
     * @return list<string> 問題点（なければ空）
     */
    public function check(Blog $blog, ?string $title, ?string $meta, ?string $mainKeyword, Post|Page|null $self = null): array
    {
        $rules = (array) config('blogos.title_checks');
        $keyChars = (int) ($rules['title_key_chars'] ?? 28);
        $issues = [];
        $title = trim((string) $title);
        $meta = trim((string) $meta);

        if ($title === '') {
            return ['タイトルがありません。'];
        }

        $length = mb_strlen($title);
        if ($length > (int) ($rules['title_max_chars'] ?? 40)) {
            $issues[] = "タイトルが長すぎます（{$length}文字。" . ($rules['title_max_chars'] ?? 40) . '文字程度まで）。';
        }
        if (config('blogos.article_html.' . $blog->quality_profile . '.title_prefix_bracket') === false && str_starts_with($title, '【')) {
            $issues[] = 'タイトルの先頭に【…】があります。検索された語が先頭に来るよう、【〈技術〉入門】はタイトルの最後に置いてください。';
        }

        $tokens = $this->tokens($mainKeyword);
        if ($tokens !== []) {
            // 最後の【…】（シリーズ名）に含まれる語は、先頭になくてよい
            $series = preg_match('/【[^】]*】\s*$/u', $title, $m) ? $m[0] : '';
            $head = mb_substr($title, 0, $keyChars);
            $missing = array_filter($tokens, fn ($token) => mb_stripos($head, $token) === false && mb_stripos($series, $token) === false);
            if ($missing !== []) {
                $issues[] = "メインキーワード（" . implode('・', $missing) . "）が、タイトルの先頭{$keyChars}文字の中にありません。";
            }
        }

        if ($meta === '') {
            $issues[] = 'メタディスクリプションが未設定です。';
        } else {
            $metaLength = mb_strlen($meta);
            $min = (int) ($rules['meta_min_chars'] ?? 80);
            $max = (int) ($rules['meta_max_chars'] ?? 120);
            if ($metaLength < $min || $metaLength > $max) {
                $issues[] = "メタディスクリプションが{$metaLength}文字です（{$min}〜{$max}文字）。";
            }
            $missing = array_filter($tokens, fn ($token) => mb_stripos($meta, $token) === false);
            if ($tokens !== [] && $missing !== []) {
                $issues[] = 'メタディスクリプションに、メインキーワード（' . implode('・', $missing) . '）がありません。';
            }
            if ($meta === $title) {
                $issues[] = 'メタディスクリプションが、タイトルと同じです。';
            }
        }

        $existing = $this->existing($blog);
        $selfKey = $self !== null ? ($self instanceof Post ? 'post:' : 'page:') . $self->id : null;
        $others = fn (array $map, string $value) => array_values(array_filter($map[$value] ?? [], fn ($key) => $key !== $selfKey));
        if ($others($existing['titles'], $title) !== []) {
            $issues[] = 'ほかの記事と同じタイトルです。';
        }
        if ($meta !== '' && $others($existing['metas'], $meta) !== []) {
            $issues[] = 'ほかの記事と同じメタディスクリプションです。';
        }

        return $issues;
    }

    /**
     * メインキーワードの語（空白で区切る）
     *
     * @return list<string>
     */
    protected function tokens(?string $keyword): array
    {
        return array_values(array_filter(preg_split('/[\s　]+/u', trim((string) $keyword)) ?: [], fn ($token) => $token !== ''));
    }

    /**
     * @return array{titles: array<string, list<string>>, metas: array<string, list<string>>}
     */
    protected function existing(Blog $blog): array
    {
        if (! isset($this->existing[$blog->id])) {
            $titles = [];
            $metas = [];
            foreach ([Post::class => 'post:', Page::class => 'page:'] as $modelClass => $prefix) {
                foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('status', 'publish')->get(['id', 'title_raw', 'meta_description_raw']) as $article) {
                    $titles[trim((string) $article->title_raw)][] = $prefix . $article->id;
                    if (filled($article->meta_description_raw)) {
                        $metas[trim((string) $article->meta_description_raw)][] = $prefix . $article->id;
                    }
                }
            }
            $this->existing[$blog->id] = ['titles' => $titles, 'metas' => $metas];
        }

        return $this->existing[$blog->id];
    }
}
