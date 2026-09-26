<?php

namespace App\Repositories;

use App\Models\ArticleMedia;
use App\Models\Category;
use App\Models\Tag;
use App\Models\InternalLink;
use App\Models\Page;
use App\Models\Post;
use App\Models\WordPressPushOperation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * 記事（投稿・固定ページ）の業務画面向けの読み取り（ARCHITECTURE 17章）。
 * 記事の種類は、URLの値（posts / pages）で指定する。
 */
class ArticleRepository
{
    public const TYPES = ['posts' => Post::class, 'pages' => Page::class];

    /**
     * @param array{q?: string, status?: string, work_status?: string, draft?: bool} $filters
     */
    public function paginate(int $blogId, string $type, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $modelClass = self::TYPES[$type];
        $column = $type === 'posts' ? 'post_id' : 'page_id';
        $keyword = trim((string) ($filters['q'] ?? ''));

        return $modelClass::query()
            ->where("{$type}.blog_id", $blogId)
            ->whereNull("{$type}.wordpress_deleted_at")
            ->leftJoin('article_managements as m', "m.{$column}", '=', "{$type}.id")
            ->select("{$type}.*", 'm.work_status', 'm.article_type')
            ->selectSub(
                fn ($query) => $query->from('article_drafts')->selectRaw('max(id)')
                    ->whereColumn($column, "{$type}.id")->whereIn('state', ['editing', 'review']),
                'active_draft_id'
            )
            ->when($keyword !== '', function ($query) use ($type, $keyword) {
                $like = '%' . addcslashes($keyword, '%_\\') . '%';
                $query->where(fn ($q) => $q->where("{$type}.title_raw", 'like', $like)->orWhere("{$type}.slug", 'like', $like));
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where("{$type}.status", $filters['status']))
            ->when(filled($filters['work_status'] ?? null), function ($query) use ($filters) {
                $filters['work_status'] === 'not_started'
                    ? $query->where(fn ($q) => $q->whereNull('m.work_status')->orWhere('m.work_status', 'not_started'))
                    : $query->where('m.work_status', $filters['work_status']);
            })
            ->orderByDesc("{$type}.wordpress_date_gmt")
            ->orderByDesc("{$type}.id")
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $blogId, string $type, int $id): Post|Page|null
    {
        $modelClass = self::TYPES[$type] ?? null;

        return $modelClass === null ? null : $modelClass::where('blog_id', $blogId)->find($id);
    }

    /**
     * 関係の登録で選ぶ記事の候補（タイトル・スラッグで検索）
     *
     * @return Collection<int, Post|Page>
     */
    public function search(int $blogId, string $type, string $keyword, int $limit = 20): Collection
    {
        $like = '%' . addcslashes(trim($keyword), '%_\\') . '%';

        return self::TYPES[$type]::where('blog_id', $blogId)
            ->existing()
            ->where(fn ($q) => $q->where('title_raw', 'like', $like)->orWhere('slug', 'like', $like))
            ->orderByDesc('wordpress_date_gmt')
            ->limit($limit)
            ->get(['id', 'wordpress_id', 'title_raw', 'slug', 'status']);
    }

    /**
     * 公開済みの記事のタイトルとURL（AIへの指示文の、内部リンクの候補）
     *
     * @return Collection<int, object{title_raw: string, link: string}>
     */
    public function publishedList(int $blogId, int $limit = 500): Collection
    {
        return Post::where('blog_id', $blogId)->existing()->where('status', 'publish')->orderByDesc('wordpress_date_gmt')->limit($limit)->get(['title_raw', 'link'])
            ->concat(Page::where('blog_id', $blogId)->existing()->where('status', 'publish')->orderBy('menu_order')->limit($limit)->get(['title_raw', 'link']));
    }

    /**
     * 記事のタイトル等をまとめて読み込む（分析の一覧など）
     *
     * @param array<int, int> $postIds
     * @param array<int, int> $pageIds
     * @return array{posts: Collection<int, Post>, pages: Collection<int, Page>} IDをキーにした記事
     */
    public function findMany(array $postIds, array $pageIds): array
    {
        return [
            'posts' => Post::whereIn('id', $postIds)->get(['id', 'title_raw', 'status', 'wordpress_deleted_at'])->keyBy('id'),
            'pages' => Page::whereIn('id', $pageIds)->get(['id', 'title_raw', 'status', 'wordpress_deleted_at'])->keyBy('id'),
        ];
    }

    /**
     * 編集案で選ぶカテゴリ・タグ（WordPress側で削除されたものを除く）
     *
     * @param string $table categories / tags
     * @return Collection<int, object{wordpress_id: int, name: string}>
     */
    public function terms(int $blogId, string $table): Collection
    {
        $modelClass = ['categories' => Category::class, 'tags' => Tag::class][$table];

        return $modelClass::where('blog_id', $blogId)->existing()->orderBy('name')->get(['wordpress_id', 'name']);
    }

    /**
     * @return Collection<int, InternalLink> この記事から出ているリンク
     */
    public function outboundLinks(Post|Page $article): Collection
    {
        return InternalLink::with(['targetPost:id,title_raw', 'targetPage:id,title_raw'])
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, InternalLink> この記事へのリンク（リンク元の記事を含む）
     */
    public function inboundLinks(Post|Page $article): Collection
    {
        return InternalLink::with(['sourcePost:id,title_raw', 'sourcePage:id,title_raw'])
            ->where('target_' . ArticleContentRepository::articleColumn($article), $article->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * この記事へのリンクの数（評価した時点の数を記録し、変わったら再評価する。D-25-04）
     */
    public function inboundLinkCount(Post|Page $article): int
    {
        return InternalLink::where('target_' . ArticleContentRepository::articleColumn($article), $article->id)->count();
    }

    /**
     * 記事ごとの「この記事へのリンク」の数
     *
     * @return array{posts: array<int, int>, pages: array<int, int>} 記事のID => 数
     */
    public function inboundLinkCounts(int $blogId): array
    {
        $counts = fn (string $column) => InternalLink::where('blog_id', $blogId)->whereNotNull($column)
            ->groupBy($column)->selectRaw("{$column} AS article_id, COUNT(*) AS link_count")
            ->pluck('link_count', 'article_id')->map(fn ($value) => (int) $value)->all();

        return ['posts' => $counts('target_post_id'), 'pages' => $counts('target_page_id')];
    }

    /**
     * 公開中の記事（まとめて実行・自動の再評価の対象）
     *
     * @return Collection<int, Post|Page>
     */
    public function publishedArticles(int $blogId): Collection
    {
        $columns = ['id', 'blog_id', 'title_raw', 'status', 'link', 'wordpress_modified_gmt'];

        return Post::where('blog_id', $blogId)->existing()->where('status', 'publish')->orderBy('id')->get($columns)
            ->concat(Page::where('blog_id', $blogId)->existing()->where('status', 'publish')->orderBy('id')->get($columns));
    }

    /**
     * @return Collection<int, ArticleMedia>
     */
    public function media(Post|Page $article): Collection
    {
        return ArticleMedia::with('media:id,wordpress_id,title_raw')
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, WordPressPushOperation>
     */
    public function pushOperations(Post|Page $article): Collection
    {
        return WordPressPushOperation::with('approver:id,name')
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
