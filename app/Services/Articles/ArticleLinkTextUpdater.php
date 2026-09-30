<?php

namespace App\Services\Articles;

use App\Models\Blog;
use App\Models\InternalLink;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticlePathRepository;
use Illuminate\Support\Facades\DB;

/**
 * タイトルが変わった記事へのリンクの文字を、新しいタイトルに直す（D-46）。
 *
 * 記事へのリンクの文字は、仕上げのときのリンク先のタイトルで決まるため、後からリンク先のタイトルが変わると古いままになる。
 * リンクの文字が、リンク先の記事の以前のタイトル（同期・反映の履歴の title_raw）と同じものだけを直す（人が書いた文字は変えない）。
 * 中に別のタグがあるリンク（画像のリンクなど）は変えない。
 */
class ArticleLinkTextUpdater
{
    /**
     * @var array<int, array<string, array{title: string, old: list<string>}>> ブログごとの「post:ID / page:ID => 今のタイトルと以前のタイトル」
     */
    protected array $renamed = [];

    public function __construct(
        protected ArticlePathRepository $paths,
    ) {
    }

    public function forget(int $blogId): void
    {
        unset($this->renamed[$blogId]);
        $this->paths->forget($blogId);
    }

    /**
     * タイトルが変わったことのある記事
     *
     * @return array<string, array{title: string, old: list<string>}>
     */
    public function renamed(int $blogId): array
    {
        if (isset($this->renamed[$blogId])) {
            return $this->renamed[$blogId];
        }

        $result = [];
        foreach (['post' => [Post::class, 'post_histories', 'post_id'], 'page' => [Page::class, 'page_histories', 'page_id']] as $type => [$modelClass, $table, $column]) {
            $rows = DB::table($table)->where('field', 'title_raw')->whereIn($column, $modelClass::where('blog_id', $blogId)->select('id'))
                ->get([$column, 'old_value', 'new_value'])->groupBy($column);
            if ($rows->isEmpty()) {
                continue;
            }
            $current = $modelClass::where('blog_id', $blogId)->existing()->whereIn('id', $rows->keys())->pluck('title_raw', 'id');
            foreach ($rows as $id => $changes) {
                if (! isset($current[$id])) {
                    continue;
                }
                $title = self::normalize((string) $current[$id]);
                $old = $changes->flatMap(fn ($row) => [$row->old_value, $row->new_value])->filter()->map(fn ($value) => self::normalize((string) $value))
                    ->reject(fn ($value) => $value === '' || $value === $title)->unique()->values()->all();
                if ($old !== []) {
                    $result["{$type}:{$id}"] = ['title' => (string) $current[$id], 'old' => $old];
                }
            }
        }

        return $this->renamed[$blogId] = $result;
    }

    /**
     * 本文のリンクの文字のうち、リンク先の以前のタイトルのままのものを、今のタイトルに直す
     *
     * @return array{content: string, notes: list<string>}
     */
    public function refresh(Blog $blog, string $content): array
    {
        $renamed = $this->renamed($blog->id);
        if ($renamed === [] || ! str_contains($content, '<a')) {
            return ['content' => $content, 'notes' => []];
        }

        $notes = [];
        $updated = preg_replace_callback('/(<a\s[^>]*?href\s*=\s*(["\']))(.*?)(\2[^>]*>)(.*?)(<\/a>)/is', function (array $m) use ($blog, $renamed, &$notes) {
            if (preg_match('/<[a-z]/i', $m[5])) {
                return $m[0];
            }
            $article = $this->article($blog->id, html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5), $renamed);
            $text = self::normalize(html_entity_decode($m[5], ENT_QUOTES | ENT_HTML5));
            if ($article === null || ! in_array($text, $article['old'], true)) {
                return $m[0];
            }
            $notes[] = "タイトルが変わった記事へのリンクの文字を直しました：「{$text}」→「{$article['title']}」";

            return $m[1] . $m[3] . $m[4] . e($article['title']) . $m[6];
        }, $content);

        return ['content' => $updated ?? $content, 'notes' => array_values(array_unique($notes))];
    }

    /**
     * 公開中の記事のうち、以前のタイトルのままのリンクがある記事（同期で取り出した内部リンクから判定する）
     *
     * @return array<string, array{article: Post|Page, titles: list<string>}> post:ID / page:ID => 記事と直すリンク先のタイトル
     */
    public function staleArticles(Blog $blog): array
    {
        $renamed = $this->renamed($blog->id);
        if ($renamed === []) {
            return [];
        }

        $result = [];
        foreach (InternalLink::where('blog_id', $blog->id)->where(fn ($q) => $q->whereNotNull('target_post_id')->orWhereNotNull('target_page_id'))->get() as $link) {
            $target = $renamed[$link->target_post_id !== null ? "post:{$link->target_post_id}" : "page:{$link->target_page_id}"] ?? null;
            if ($target === null || ! in_array(self::normalize((string) $link->anchor_text), $target['old'], true)) {
                continue;
            }
            $key = $link->post_id !== null ? "post:{$link->post_id}" : "page:{$link->page_id}";
            if (! isset($result[$key])) {
                $article = $link->post_id !== null ? Post::where('blog_id', $blog->id)->existing()->find($link->post_id) : Page::where('blog_id', $blog->id)->existing()->find($link->page_id);
                if ($article === null) {
                    continue;
                }
                $result[$key] = ['article' => $article, 'titles' => []];
            }
            $result[$key]['titles'][] = $target['title'];
        }

        return array_map(fn ($row) => ['article' => $row['article'], 'titles' => array_values(array_unique($row['titles']))], $result);
    }

    /**
     * @param array<string, array{title: string, old: list<string>}> $renamed
     * @return array{title: string, old: list<string>}|null
     */
    protected function article(int $blogId, string $url, array $renamed): ?array
    {
        $match = $this->paths->find($blogId, $url);

        return $match !== null ? ($renamed[($match[0] === 'post_id' ? 'post' : 'page') . ":{$match[1]}"] ?? null) : null;
    }

    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
