<?php

namespace App\Services\PageSpeed;

use App\Models\Page;
use App\Models\PageSpeedRun;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * PageSpeed Insights の測定の結果のまとめ（D-78。記事の画面のパネル・PageSpeed Insights情報の画面）。
 *
 * 記事ごとの「最新の結果」は、その記事・端末で最後に成功した測定。点数の判定は Lighthouse と同じ区切り（90以上：良好・50以上：改善が必要・50未満：不良）。
 */
class PageSpeedReportService
{
    /** 区分（列 → 名前） */
    public const CATEGORIES = [
        'performance_score'    => 'パフォーマンス',
        'accessibility_score'  => 'ユーザー補助',
        'best_practices_score' => 'おすすめの方法',
        'seo_score'            => 'SEO',
    ];

    /** 応答の区分のキー → 名前（合格しなかった項目の区分） */
    public const AUDIT_CATEGORIES = [
        'performance'    => 'パフォーマンス',
        'accessibility'  => 'ユーザー補助',
        'best-practices' => 'おすすめの方法',
        'seo'            => 'SEO',
    ];

    /** 点数の判定（Lighthouse と同じ区切り） */
    public static function grade(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 90    => '良好',
            $score >= 50    => '改善が必要',
            default         => '不良',
        };
    }

    /**
     * 記事の最新の結果（端末ごと。測っていなければ null）
     *
     * @return array{mobile: PageSpeedRun|null, desktop: PageSpeedRun|null}
     */
    public function latestForArticle(Post|Page $article): array
    {
        $column = $article instanceof Post ? 'post_id' : 'page_id';
        $latest = fn (string $strategy) => PageSpeedRun::where('blog_id', $article->blog_id)->where($column, $article->id)
            ->where('strategy', $strategy)->where('status', 'succeeded')->latest('started_at')->latest('id')->first();

        return ['mobile' => $latest('mobile'), 'desktop' => $latest('desktop')];
    }

    /**
     * 記事の測定の記録（新しい順。点数の推移を見るため）
     *
     * @return Collection<int, PageSpeedRun>
     */
    public function historyForArticle(Post|Page $article, int $limit = 10): Collection
    {
        $column = $article instanceof Post ? 'post_id' : 'page_id';

        return PageSpeedRun::where('blog_id', $article->blog_id)->where($column, $article->id)
            ->latest('started_at')->latest('id')->limit($limit)->get();
    }

    /**
     * トップページの最新の結果
     */
    public function latestForHome(int $blogId, string $strategy): ?PageSpeedRun
    {
        return PageSpeedRun::where('blog_id', $blogId)->whereNull('post_id')->whereNull('page_id')
            ->where('strategy', $strategy)->where('status', 'succeeded')->latest('started_at')->latest('id')->first();
    }

    /**
     * 記事ごとの最新の結果の問い合わせ（端末ごと。トップページは除く）
     */
    public function latestArticleRuns(int $blogId, string $strategy): Builder
    {
        $ids = PageSpeedRun::where('blog_id', $blogId)->where('strategy', $strategy)->where('status', 'succeeded')
            ->where(fn ($query) => $query->whereNotNull('post_id')->orWhereNotNull('page_id'))
            ->selectRaw('MAX(id)')->groupBy('post_id', 'page_id');

        return PageSpeedRun::whereIn('id', $ids);
    }

    /**
     * サイト全体のまとめ（記事ごとの最新の結果から）：測った記事の数・公開中の記事の数・区分ごとの平均と判定の分布・実際の利用者の判定の分布・
     * サイト全体の実際の利用者の値（一番新しい測定のもの）
     *
     * @return array<string, mixed>
     */
    public function summary(int $blogId, string $strategy): array
    {
        $runs = $this->latestArticleRuns($blogId, $strategy)->get();

        $categories = [];
        foreach (self::CATEGORIES as $column => $label) {
            $scores = $runs->pluck($column)->filter(fn ($score) => $score !== null);
            $categories[$column] = [
                'label'   => $label,
                'average' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
                'grades'  => ['良好' => $scores->filter(fn ($s) => $s >= 90)->count(), '改善が必要' => $scores->filter(fn ($s) => $s >= 50 && $s < 90)->count(), '不良' => $scores->filter(fn ($s) => $s < 50)->count()],
            ];
        }

        $origin = PageSpeedRun::where('blog_id', $blogId)->where('strategy', $strategy)->where('status', 'succeeded')
            ->whereNotNull('origin_category')->latest('started_at')->latest('id')->first();

        return [
            'measured'   => $runs->count(),
            'published'  => Post::where('blog_id', $blogId)->existing()->where('status', 'publish')->count() + Page::where('blog_id', $blogId)->existing()->where('status', 'publish')->count(),
            'categories' => $categories,
            'field'      => $runs->whereNotNull('field_category')->countBy('field_category')->all(),
            'origin'     => $origin,
        ];
    }

    /**
     * 多くの記事で合格しなかった項目（記事ごとの最新の結果で、その項目が合格しなかった記事の数の多い順）
     *
     * @return list<array{id: string, title: string, categories: list<string>, count: int}>
     */
    public function frequentFailures(int $blogId, string $strategy, int $limit = 20): array
    {
        $counts = [];
        foreach ($this->latestArticleRuns($blogId, $strategy)->get(['id', 'failed_audits']) as $run) {
            foreach ((array) $run->failed_audits as $audit) {
                $id = $audit['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $counts[$id] ??= ['id' => $id, 'title' => $audit['title'] ?? $id, 'categories' => $audit['categories'] ?? [], 'count' => 0];
                $counts[$id]['count']++;
            }
        }
        usort($counts, fn ($a, $b) => [$b['count'], $a['id']] <=> [$a['count'], $b['id']]);

        return array_slice($counts, 0, $limit);
    }

    /**
     * 記事ごとの最新の結果の一覧（並びの列の昇順。点数の低い記事から見るため）
     */
    public function articles(int $blogId, string $strategy, string $sort, int $perPage): LengthAwarePaginator
    {
        $sort = array_key_exists($sort, self::CATEGORIES) ? $sort : 'performance_score';

        return $this->latestArticleRuns($blogId, $strategy)
            ->with(['post:id,title_raw', 'page:id,title_raw'])
            ->orderBy($sort)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
