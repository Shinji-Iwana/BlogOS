<?php

namespace App\Services\Topics;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryLaunch;
use App\Models\CategoryLaunchChild;
use App\Models\TopicSuggestion;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use App\Support\QualityProfiles;
use Illuminate\Support\Collection;

/**
 * カテゴリの立ち上げ（D-41）：①親カテゴリ → ②子カテゴリ → ③記事の企画 → ④記事の編集案 → ⑤子ロードマップの編集案。
 *
 * 各段階は、人が画面で実行・確認してから次へ進む。記事の編集案と子ロードマップは API 実行で作る（費用がかかる）。
 * 公開（カテゴリの作成・まとめて公開）と親ロードマップは、次の段階で作る。
 */
class CategoryLaunchService
{
    /**
     * 子カテゴリごとに企画・作成する記事の数
     */
    public const ARTICLES_PER_CHILD = 10;

    public function __construct(
        protected AiRunService $runService,
    ) {
    }

    /**
     * @param list<int> $categoryIds 既存の子カテゴリ
     * @param list<int> $suggestionIds 採用した子カテゴリの案
     */
    public function create(Blog $blog, Category $parent, array $categoryIds, array $suggestionIds, ?int $userId): CategoryLaunch
    {
        $launch = CategoryLaunch::create(['blog_id' => $blog->id, 'parent_category_id' => $parent->id, 'status' => 'active', 'created_by' => $userId]);
        $this->addChildren($launch, $categoryIds, $suggestionIds);

        return $launch;
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $suggestionIds
     */
    public function addChildren(CategoryLaunch $launch, array $categoryIds, array $suggestionIds): int
    {
        $existing = $launch->children()->get(['category_id', 'topic_suggestion_id']);
        $added = 0;

        foreach (Category::where('blog_id', $launch->blog_id)->where('parent_id', $launch->parent_category_id)->whereIn('id', $categoryIds)->get() as $category) {
            if (! $existing->contains('category_id', $category->id)) {
                $launch->children()->create(['category_id' => $category->id, 'name' => $category->name, 'slug' => $category->slug]);
                $added++;
            }
        }

        $suggestions = TopicSuggestion::where('blog_id', $launch->blog_id)->where('type', 'category')->where('category_id', $launch->parent_category_id)
            ->where('status', SuggestionStatus::Accepted)->whereIn('id', $suggestionIds)->get();
        foreach ($suggestions as $suggestion) {
            if (! $existing->contains('topic_suggestion_id', $suggestion->id)) {
                $launch->children()->create(['topic_suggestion_id' => $suggestion->id, 'name' => $suggestion->title, 'slug' => $suggestion->slug, 'scope' => $suggestion->scope]);
                $added++;
            }
        }

        return $added;
    }

    /**
     * ③ 子カテゴリの記事を企画する（記事の企画。10件）
     *
     * @throws AiException
     */
    public function planArticles(CategoryLaunchChild $child, AiExecutionMethod $method, ?string $model, ?string $effort, bool $webSearch, ?int $userId): AiGeneration
    {
        $launch = $child->launch()->with('blog', 'parentCategory')->firstOrFail();

        return $this->runService->start(AiMode::TopicPlanning, $launch->blog, null, null, array_filter([
            '企画の単位'       => TopicPlanningPromptValues::UNITS['articles'],
            '企画の単位の値'   => 'articles',
            'カテゴリ'         => $child->name,
            'カテゴリの値'     => (string) ($child->category_id ?? $launch->parent_category_id),
            '立ち上げの子の値' => (string) $child->id,
            '補足'             => 'この子カテゴリで最初に書く記事を、' . self::ARTICLES_PER_CHILD . '件ちょうど企画してください（子ロードマップの学習の順番で、基本から順に）。',
        ]), null, $userId, $method, $model, $effort, webSearch: $webSearch);
    }

    /**
     * ④ 採用した記事の案から、新規記事の編集案をまとめて作る（API 実行。作成中・作成済みの案は除く）
     *
     * @return array{started: int, errors: list<string>}
     */
    public function generateArticles(CategoryLaunchChild $child, ?string $model, ?string $effort, ?int $userId): array
    {
        $launch = $child->launch()->with('blog', 'parentCategory')->firstOrFail();
        $types = QualityProfiles::articleTypes($launch->blog->quality_profile);
        $suggestions = $this->acceptedArticles($child);
        $siblings = $suggestions->pluck('title')->map(fn ($title) => "- {$title}")->implode("\n");

        $started = 0;
        $errors = [];
        foreach ($suggestions as $suggestion) {
            if ($suggestion->article_draft_id !== null || $this->isRunning($suggestion)) {
                continue;
            }

            try {
                $generation = $this->runService->start(AiMode::NewArticle, $launch->blog, null, null, array_filter([
                    '記事の種類'     => '投稿',
                    '記事種類'       => $suggestion->article_type ? ($types['types'][$suggestion->article_type] ?? $suggestion->article_type) : null,
                    '記事種類の値'   => $suggestion->article_type,
                    '細分類'         => $suggestion->article_subtype ? ($types['subtypes'][$suggestion->article_subtype] ?? $suggestion->article_subtype) : null,
                    'メインキーワード' => $suggestion->main_keyword,
                    'サブキーワード' => implode("\n", (array) $suggestion->sub_keywords),
                    '検索意図'       => $suggestion->search_intent,
                    'カテゴリ'       => $child->isNew() ? "{$launch->parentCategory?->name} ＞ {$child->name}（新しい子カテゴリ）" : $child->name,
                    'カテゴリの値'   => $child->category_id !== null ? (string) $child->category_id : null,
                    '記事の企画の値' => (string) $suggestion->id,
                    '補足（実体験・検証の結果・伝えたいこと）' => "タイトル案：{$suggestion->title}"
                        . ($suggestion->roadmap_step ? "\nロードマップのステップ：{$suggestion->roadmap_step}" : '')
                        . "\nこの記事は、子カテゴリ「{$child->name}」の最初の記事の1つです。同じ子カテゴリの記事（まだ公開していない）：\n{$siblings}",
                ], fn ($value) => filled($value)), null, $userId, AiExecutionMethod::Api, $model, $effort);
                $suggestion->update(['article_generation_id' => $generation->id]);
                $started++;
            } catch (AiException $e) {
                $errors[] = "「{$suggestion->title}」：{$e->getMessage()}";
                // 残高の見込みなどで止まった場合は、続けても同じため止める
                break;
            }
        }

        return ['started' => $started, 'errors' => $errors];
    }

    /**
     * ⑤ 子ロードマップの編集案を作る（記事の編集案がすべてそろってから）
     *
     * @throws AiException
     */
    public function generateRoadmap(CategoryLaunchChild $child, ?string $model, ?string $effort, ?int $userId): AiGeneration
    {
        $launch = $child->launch()->with('blog', 'parentCategory')->firstOrFail();
        $suggestions = $this->acceptedArticles($child)->load('articleDraft:id,title_raw,post_id,page_id');
        if ($suggestions->isEmpty() || $suggestions->contains(fn ($suggestion) => $suggestion->article_draft_id === null)) {
            throw new AiException('採用した記事の案すべての編集案ができてから、子ロードマップを作ってください。');
        }

        $types = QualityProfiles::articleTypes($launch->blog->quality_profile);
        $articles = $suggestions->map(fn ($suggestion) => "- [[記事:下書き{$suggestion->article_draft_id}]] {$suggestion->articleDraft?->title_raw}"
            . ($suggestion->roadmap_step ? "（ステップ：{$suggestion->roadmap_step}）" : ''))->implode("\n");

        return $this->runService->start(AiMode::NewArticle, $launch->blog, null, null, [
            '記事の種類'       => '固定ページ',
            '記事種類'         => $types['types']['child_roadmap'] ?? '子ロードマップ',
            '記事種類の値'     => 'child_roadmap',
            'メインキーワード' => trim("{$launch->parentCategory?->name} {$child->name} ロードマップ"),
            '立ち上げの子の値' => (string) $child->id,
            '補足（実体験・検証の結果・伝えたいこと）' => "子カテゴリ「{$child->name}」の子ロードマップ（学習ガイド）を作ってください。"
                . ($child->scope ? "\nこのカテゴリの範囲：{$child->scope}" : '')
                . "\nこのカテゴリの記事（まだ公開していない新しい記事。本文には、この目印をそのまま書いてください）：\n{$articles}"
                . "\nこれらの記事を、学習の順番のステップ（html-rules.md 3-15 の roadmap-step）に分けて、すべて載せてください。",
        ], null, $userId, AiExecutionMethod::Api, $model, $effort);
    }

    /**
     * @return Collection<int, TopicSuggestion>
     */
    public function acceptedArticles(CategoryLaunchChild $child): Collection
    {
        return $child->articleSuggestions()->where('type', 'article')->where('status', SuggestionStatus::Accepted)->orderBy('id')->get();
    }

    /**
     * 子カテゴリの進み具合（画面の表示用）
     *
     * @return array{step: string, pending: int, accepted: int, running: int, drafts: int, roadmap: bool}
     */
    public function progress(CategoryLaunchChild $child): array
    {
        $suggestions = $child->articleSuggestions()->where('type', 'article')->with('articleGeneration:id,status')->get();
        $accepted = $suggestions->where('status', SuggestionStatus::Accepted);
        $running = $accepted->filter(fn ($suggestion) => $suggestion->article_draft_id === null && $this->isRunning($suggestion))->count();
        $drafts = $accepted->whereNotNull('article_draft_id')->count();

        $step = match (true) {
            $child->roadmap_draft_id !== null                        => '⑤ 子ロードマップの編集案まで完了',
            $accepted->isNotEmpty() && $drafts === $accepted->count() => '④ 記事の編集案がそろった（次は子ロードマップ）',
            $drafts > 0 || $running > 0                               => '④ 記事の編集案を作成中',
            $accepted->isNotEmpty()                                   => '③ 記事の案を採用済み（次は記事の編集案）',
            $suggestions->where('status', SuggestionStatus::Pending)->isNotEmpty() => '③ 記事の案の確認待ち',
            default                                                   => '③ 記事の企画の前',
        };

        return [
            'step'     => $step,
            'pending'  => $suggestions->where('status', SuggestionStatus::Pending)->count(),
            'accepted' => $accepted->count(),
            'running'  => $running,
            'drafts'   => $drafts,
            'roadmap'  => $child->roadmap_draft_id !== null,
        ];
    }

    protected function isRunning(TopicSuggestion $suggestion): bool
    {
        $generation = $suggestion->relationLoaded('articleGeneration') ? $suggestion->articleGeneration : $suggestion->articleGeneration()->first();

        return $generation !== null && in_array($generation->status, [AiGenerationStatus::Running, AiGenerationStatus::WaitingOutput], true);
    }
}
