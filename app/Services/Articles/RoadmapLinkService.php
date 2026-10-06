<?php

namespace App\Services\Articles;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\RevisionScope;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleManagementRepository;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;

/**
 * 孤立記事をロードマップに載せる（D-70-06）。
 *
 * 子ロードマップに載っていない記事（InternalLinkChecker の not_in_roadmap。どこからもリンクされない孤立記事を含む）を、
 * カテゴリの子ロードマップのページごとにまとめ、そのページの改修（記事を適切なステップに載せることだけ）を API で実行して、編集案にする。
 * 記事の改修では直せない必須条件 req.not_orphan を直すため（記事の改修の指摘からは外している。RevisionFindingService::NOT_FIXABLE_BY_REVISION）。
 * 記事の再評価（ai:auto-reevaluate）から、改修まで有効にしたブログで呼ぶ。WordPress への反映は人が行う（D-07-01）。
 *
 * 作らない場合：ロードマップのページに作業中の編集案がある（人の作業を消さない・二重に作らない）、同じページの改修を実行中。
 * カテゴリのロードマップのページがない記事は、内部リンクの確認の画面に出す（カテゴリの立ち上げで作る）。
 */
class RoadmapLinkService
{
    /**
     * 改修の「人が提供した情報」に入れる、載せる記事の一覧のラベル（PromptBuilder がこのラベルを見て、指摘を渡さない）
     */
    public const PARAMETER = 'ロードマップに載せる記事';

    public function __construct(
        protected InternalLinkChecker $links,
        protected ArticleDraftRepository $drafts,
        protected ArticleManagementRepository $managements,
        protected AiRunService $runService,
    ) {
    }

    /**
     * ロードマップのページごとに、載っていない記事（ページ => 記事）
     *
     * @return array<int, array{page: Page, posts: list<Post>}>
     */
    public function targets(Blog $blog): array
    {
        $groups = [];
        foreach ($this->links->check($blog)['articles'] as $row) {
            if ($row['kind'] !== 'not_in_roadmap' || ! $row['article'] instanceof Post) {
                continue;
            }
            $category = $row['article']->categories->first();
            $page = $category !== null ? $this->links->roadmapPage($category) : null;
            if ($page === null) {
                continue;
            }
            $groups[$page->id] ??= ['page' => Page::find($page->id), 'posts' => []];
            $groups[$page->id]['posts'][] = $row['article'];
        }

        return $groups;
    }

    /**
     * ロードマップのページの改修を登録する（1日に $limit ページまで）
     *
     * @return list<string> 結果（登録した・登録しなかった理由）
     */
    public function run(Blog $blog, string $model, string $effort, ?int $userId = null, int $limit = 3): array
    {
        $results = [];
        foreach ($this->targets($blog) as $group) {
            $page = $group['page'];
            if (count($results) >= $limit) {
                $results[] = "ロードマップ「{$page->title_raw}」：今日の上限（{$limit}ページ）のため、明日以降に登録します。";

                continue;
            }
            if ($this->drafts->activeFor($page) !== null) {
                $results[] = "ロードマップ「{$page->title_raw}」：作業中の編集案があるため、登録しませんでした（載っていない記事 " . count($group['posts']) . '件）。';

                continue;
            }
            if (AiGeneration::where('page_id', $page->id)->where('purpose', AiMode::Revision)->where('status', AiGenerationStatus::Running)->exists()) {
                continue;
            }

            try {
                $generation = $this->runService->start(AiMode::Revision, $blog, $page, null, [self::PARAMETER => $this->describe($group['posts'])],
                    RevisionScope::Minor, $userId, AiExecutionMethod::Api, $model, $effort);
                $results[] = "ロードマップ「{$page->title_raw}」：" . count($group['posts']) . "件の記事を載せる改修を登録しました（AI 実行 #{$generation->id}）。";
            } catch (AiException $e) {
                $results[] = "ロードマップ「{$page->title_raw}」：{$e->getMessage()}";
            }
        }

        return $results;
    }

    /**
     * この記事をロードマップに載せている作業中の編集案（記事の編集案の画面に「対応中」と出す）
     */
    public function pendingDraftFor(Post $post): ?\App\Models\ArticleDraft
    {
        // parameters は JSON（日本語はエスケープされる）のため、読み出してから探す。ロードマップの作業中の編集案の元になった改修だけを見る
        $generationIds = AiGeneration::where('blog_id', $post->blog_id)->where('purpose', AiMode::Revision)->whereNotNull('page_id')
            ->whereIn('id', \App\Models\ArticleDraft::where('blog_id', $post->blog_id)->whereNotNull('page_id')->whereNotNull('ai_generation_id')->select('ai_generation_id'))
            ->get(['id', 'parameters'])
            ->filter(fn (AiGeneration $generation) => str_contains((string) (((array) $generation->parameters)[self::PARAMETER] ?? ''), "[[記事:{$post->wordpress_id}]]"))
            ->pluck('id');
        if ($generationIds->isEmpty()) {
            return null;
        }

        return \App\Models\ArticleDraft::whereIn('ai_generation_id', $generationIds)->latest('id')->get()->first(fn ($draft) => $draft->state->isActive());
    }

    /**
     * @param list<Post> $posts
     */
    protected function describe(array $posts): string
    {
        return collect($posts)->map(function (Post $post) {
            $management = $this->managements->findFor($post);

            return "[[記事:{$post->wordpress_id}]] {$post->title_raw}"
                . ($management?->main_search_intent ? "（主の検索意図：{$management->main_search_intent}）" : '')
                . ($management?->target_versions ? "（対象のバージョン：{$management->target_versions}）" : '');
        })->implode("\n");
    }
}
