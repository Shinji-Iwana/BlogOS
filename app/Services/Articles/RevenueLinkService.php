<?php

namespace App\Services\Articles;

use App\Enums\ChangeSource;
use App\Enums\RevisionScope;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Post;
use App\Models\RevenueArticleLink;
use App\Models\TopicSuggestion;
use App\Repositories\ArticleDraftRepository;
use App\Services\Push\PushException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 公開された収益記事へのリンクを、集客記事に入れる（D-93-02）。
 *
 * 品質基準（si-note article-types.md 4-4）：集客記事の教材の紹介の最後に、同じカテゴリの収益記事へのリンクを1つ置く。
 * 収益記事が公開されたら、同じカテゴリの集客記事と、企画の案が「リンクを置く集客記事」に選んだ記事に、決まった1文を入れる（AIは使わない）。
 * 作業中の編集案がある記事は、その最新の編集案に足す。ない記事は、この1文だけを足した編集案を作る。WordPress への反映は人が確認して行う。
 * 教材の紹介の枠がない記事には入れず、記録だけ残す（次の改修で AI が入れる。枠ができたら、次の処理で入れ直す）。
 */
class RevenueLinkService
{
    public const REASON = 'revenue_link';

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected DraftService $draftService,
        protected ArticleHtmlFinisher $finisher,
    ) {
    }

    /**
     * 公開中の収益記事
     *
     * @return Collection<int, Post>
     */
    public function revenuePosts(int $blogId): Collection
    {
        return Post::where('posts.blog_id', $blogId)->existing()->where('posts.status', 'publish')
            ->whereExists(fn ($q) => $q->from('article_managements')->whereColumn('article_managements.post_id', 'posts.id')->where('article_managements.article_type', 'revenue'))
            ->orderBy('posts.id')->get();
    }

    /**
     * 記事のカテゴリ（と、その親のカテゴリ）にある、公開中の収益記事。改修・新規記事の作成の指示文に渡す（D-93-03）
     *
     * @param list<int> $categoryIds
     * @return Collection<int, Post>
     */
    public function revenueForCategories(int $blogId, array $categoryIds): Collection
    {
        if ($categoryIds === []) {
            return collect();
        }
        $ids = $categoryIds;
        $parents = DB::table('categories')->whereIn('id', $categoryIds)->whereNotNull('parent_id')->pluck('parent_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_merge($ids, $parents)));
        $postIds = DB::table('post_categories')->whereIn('category_id', $ids)->pluck('post_id')->unique()->all();

        return $this->revenuePosts($blogId)->whereIn('id', $postIds)->values();
    }

    /**
     * 公開中の収益記事ごとに、まだリンクを入れていない集客記事に入れる
     *
     * @return int 編集案に入れた数
     */
    public function apply(Blog $blog, ?int $userId = null): int
    {
        if ($blog->isArchived()) {
            return 0;
        }

        $added = 0;
        foreach ($this->revenuePosts($blog->id) as $revenue) {
            $done = RevenueArticleLink::where('revenue_post_id', $revenue->id)->whereIn('status', [RevenueArticleLink::ADDED, RevenueArticleLink::LINKED])->pluck('post_id')->all();
            foreach ($this->targets($blog, $revenue)->reject(fn (Post $post) => in_array($post->id, $done, true)) as $target) {
                $added += $this->insert($blog, $revenue, $target, $userId) ? 1 : 0;
            }
        }

        return $added;
    }

    /**
     * リンクを入れる集客記事：収益記事と同じカテゴリの公開中の集客記事と、企画の案がリンクを置く記事に選んだ記事
     *
     * @return Collection<int, Post>
     */
    public function targets(Blog $blog, Post $revenue): Collection
    {
        $categoryIds = DB::table('post_categories')->where('post_id', $revenue->id)->pluck('category_id')->all();
        $sameCategory = DB::table('post_categories')->whereIn('category_id', $categoryIds)->pluck('post_id')->all();

        // 企画の案（この収益記事の編集案を作った案）の、リンクを置く集客記事（D-83-04）
        $draftIds = ArticleDraft::where('blog_id', $blog->id)->where('post_id', $revenue->id)->pluck('id')->all();
        $chosen = TopicSuggestion::where('blog_id', $blog->id)->whereIn('article_draft_id', $draftIds)->get(['link_post_ids'])
            ->flatMap(fn (TopicSuggestion $suggestion) => (array) $suggestion->link_post_ids)->map(fn ($id) => (int) $id)->all();

        return Post::where('posts.blog_id', $blog->id)->existing()->where('posts.status', 'publish')
            ->whereIn('posts.id', array_unique(array_merge($sameCategory, $chosen)))
            ->where('posts.id', '!=', $revenue->id)
            ->whereExists(fn ($q) => $q->from('article_managements')->whereColumn('article_managements.post_id', 'posts.id')->where('article_managements.article_type', 'acquisition'))
            ->orderBy('posts.id')->get();
    }

    /**
     * @return bool 編集案に入れたか
     */
    protected function insert(Blog $blog, Post $revenue, Post $target, ?int $userId): bool
    {
        $draft = $this->drafts->activeFor($target);
        if ($draft !== null && $draft->isLocked()) {
            // 反映の結果待ち。次の処理で入れる
            return false;
        }

        $content = (string) ($draft?->content_raw ?? $target->content_raw);
        if ($this->hasLink($revenue, $content)) {
            $this->record($blog, $revenue, $target, RevenueArticleLink::LINKED, $draft);

            return false;
        }

        $close = $this->finisher->lastMaterialBoxClose($content);
        if ($close === null) {
            $this->record($blog, $revenue, $target, RevenueArticleLink::NO_BOX, $draft);

            return false;
        }

        $created = false;
        if ($draft === null) {
            try {
                $draft = $this->draftService->createFromArticle($target, RevisionScope::Minor, $userId);
            } catch (PushException $e) {
                Log::info('収益記事へのリンクの編集案を作れませんでした。', ['post' => $target->id, 'message' => $e->getMessage()]);

                return false;
            }
            $created = true;
        }

        $this->drafts->update($draft, ['content_raw' => substr($content, 0, $close) . self::sentence($revenue) . "\n" . substr($content, $close)], ChangeSource::System, $userId);
        $note = "収益記事「{$revenue->title_raw}」へのリンクを、教材の紹介の最後に足しました。";
        $draft->forceFill(array_filter([
            'auto_reason'  => $created ? self::REASON : null,
            'finish_notes' => array_values(array_unique(array_merge((array) $draft->finish_notes, [$note]))),
        ], fn ($value) => $value !== null))->save();
        $this->record($blog, $revenue, $target, RevenueArticleLink::ADDED, $draft);

        return true;
    }

    /**
     * 教材の紹介の最後に置く1文（article-types.md 4-4 の例に合わせる）
     */
    public static function sentence(Post $revenue): string
    {
        $path = $revenue->normalized_path ?: (string) parse_url((string) $revenue->link, PHP_URL_PATH);
        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES);

        return "  <p>ほかの教材と比べて選びたい人は、<a href=\"{$e($path ?: (string) $revenue->link)}\">{$e((string) $revenue->title_raw)}</a>で比べています。</p>";
    }

    /**
     * 本文に、収益記事へのリンク（または目印）がもうあるか
     */
    protected function hasLink(Post $revenue, string $content): bool
    {
        $needles = array_filter([
            "[[記事:{$revenue->wordpress_id}]]",
            "<!-- blogos:記事:{$revenue->wordpress_id} -->",
            $revenue->normalized_path ? "href=\"{$revenue->normalized_path}\"" : null,
            $revenue->normalized_path ? "href=\"{$revenue->normalized_path}/\"" : null,
            $revenue->link ? "href=\"{$revenue->link}\"" : null,
        ]);
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function record(Blog $blog, Post $revenue, Post $target, string $status, ?ArticleDraft $draft): void
    {
        RevenueArticleLink::updateOrCreate(
            ['revenue_post_id' => $revenue->id, 'post_id' => $target->id],
            ['blog_id' => $blog->id, 'status' => $status, 'article_draft_id' => $draft?->id],
        );
    }
}
