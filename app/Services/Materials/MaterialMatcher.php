<?php

namespace App\Services\Materials;

use App\Enums\KeywordType;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\MaterialRepository;
use Illuminate\Support\Collection;

/**
 * 記事に合う教材の候補を、機械的に絞る（二段階の判別の一段目。D-30）。
 *
 * 記事のカテゴリ・タイトル・キーワードと、教材の分野（カテゴリ・技術の語句）の一致で候補を選び、
 * どれを使うか（使わないか）は、二段目でAIが品質基準に照らして判断する。古い教材も除かない（旧バージョンの記事があるため）。
 */
class MaterialMatcher
{
    /**
     * 候補に必ず含める、カテゴリの基礎の教材の数（あれば。D-91）
     */
    protected const BASIC_MIN = 2;

    public function __construct(
        protected MaterialRepository $materials,
        protected ArticleManagementRepository $managements,
        protected AffiliateProgramService $programs,
    ) {
    }

    /**
     * @param array{category_ids?: list<int>, keywords?: list<string>, title?: string|null, article_type?: string|null} $hints
     *        記事がまだない場合（新規記事）に、記事の代わりに使う情報
     * @return list<array{material: Material, score: int, reasons: list<string>, used: bool, basic: bool, usage: int}> used：記事で今使っている教材。basic：カテゴリの基礎の教材。usage：紹介している記事の数
     */
    public function candidates(Blog $blog, Post|Page|null $article, array $hints = [], ?int $limit = null): array
    {
        $limit ??= (int) config('blogos.materials.max_candidates');
        $context = $article !== null ? $this->articleContext($article) : [
            'category_ids' => $this->withParents($hints['category_ids'] ?? []),
            'words'        => array_values(array_filter(array_merge($hints['keywords'] ?? [], [$hints['title'] ?? null]))),
            'article_type' => $hints['article_type'] ?? null,
        ];
        $used = $article !== null ? $this->materials->forArticle($article)->pluck('material_id')->all() : [];
        $usage = $this->materials->usageCounts($blog->id);

        $rows = [];
        foreach ($this->materials->activeForBlog($blog->id) as $material) {
            [$score, $reasons] = $this->score($material, $context);
            // 提携中でないプログラムのリンクしかない教材は、候補にしない（D-33-08）
            // 情報（学べる内容など）を調べていない教材も、候補にしない。紹介文を公開されている情報に基づいて書けないため（D-35-04）
            if ($score > 0 && $this->allowedFor($material, $context['article_type']) && $this->programs->isUsable($material) && filled($material->summary)) {
                $rows[$material->id] = [
                    'material' => $material,
                    'score'    => $score,
                    'reasons'  => $reasons,
                    'used'     => in_array($material->id, $used, true),
                    'basic'    => $this->isBasic($material, $context['category_ids']),
                    'usage'    => $usage[$material->id] ?? 0,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b['score'], $b['material']->published_on?->timestamp ?? 0] <=> [$a['score'], $a['material']->published_on?->timestamp ?? 0]);
        $rest = array_slice($rows, $limit);
        $rows = array_slice($rows, 0, $limit);

        // 集客記事は教材を必ず1件紹介する（記事に合う教材がなければ、カテゴリの基礎の教材。D-91）。
        // 点数の高い教材で上限が埋まっても、基礎の教材を選べるように、基礎の教材を2件まで加える（書籍を先に。紹介している記事の少ない順）
        $basicCount = count(array_filter($rows, fn ($row) => $row['basic']));
        if ($basicCount < self::BASIC_MIN) {
            $extra = array_values(array_filter($rest, fn ($row) => $row['basic']));
            usort($extra, fn ($a, $b) => [$a['material']->kind->value !== 'book', $a['usage']] <=> [$b['material']->kind->value !== 'book', $b['usage']]);
            $rows = array_merge($rows, array_slice($extra, 0, self::BASIC_MIN - $basicCount));
        }

        // 今使っている教材は、見直しのために必ず含める（使わないにした教材も含む）
        $included = array_map(fn ($row) => $row['material']->id, $rows);
        foreach ($article !== null ? $this->materials->forArticle($article) : [] as $record) {
            if ($record->material !== null && ! in_array($record->material_id, $included, true)) {
                $problems = $this->programs->isUsable($record->material) ? [] : ['提携中でないため紹介に使えない（' . implode('／', $this->programs->problems($record->material)) . '）。別の教材に差し替えるか、紹介をやめる'];
                $rows[] = ['material' => $record->material, 'score' => 0, 'reasons' => array_merge(['記事で使っている'], $problems), 'used' => true, 'basic' => false, 'usage' => $usage[$record->material_id] ?? 0];
            }
        }

        return array_values($rows);
    }

    /**
     * 紹介できる教材の候補の数（品質診断で、集客記事が教材を紹介していないことを判定するため。D-91）
     *
     * @return array{count: int, basic: int}
     */
    public function candidateCounts(Blog $blog, Post|Page $article): array
    {
        $rows = array_filter($this->candidates($blog, $article), fn ($row) => $row['score'] > 0);

        return ['count' => count($rows), 'basic' => count(array_filter($rows, fn ($row) => $row['basic']))];
    }

    /**
     * カテゴリの基礎の教材：記事のカテゴリ（親を含む）に登録した、「基礎から体系的に学びたい」場面の教材（D-91）
     *
     * @param list<int> $categoryIds
     */
    protected function isBasic(Material $material, array $categoryIds): bool
    {
        return in_array('systematic', (array) $material->scenes, true)
            && $material->categories->contains(fn (Category $category) => in_array($category->id, $categoryIds, true));
    }

    /**
     * 記事種類ごとの扱い（品質基準 si-note/article-types.md 2-4・3-4）。ロードマップは体系的に学べる教材だけ（数の上限はAIが品質基準で判断する）
     */
    protected function allowedFor(Material $material, ?string $articleType): bool
    {
        return match ($articleType) {
            'parent_roadmap', 'child_roadmap' => in_array('systematic', (array) $material->scenes, true),
            default                           => true,
        };
    }

    /**
     * @param array{category_ids: list<int>, words: list<string>, article_type: string|null} $context
     * @return array{0: int, 1: list<string>}
     */
    protected function score(Material $material, array $context): array
    {
        $score = 0;
        $reasons = [];

        $categories = $material->categories->filter(fn (Category $category) => in_array($category->id, $context['category_ids'], true));
        if ($categories->isNotEmpty()) {
            $score += 3 * $categories->count();
            $reasons[] = 'カテゴリ：' . $categories->pluck('name')->implode('、');
        }

        $haystack = mb_strtolower(implode(' ', $context['words']));
        $topics = collect((array) $material->topics)->filter(fn ($topic) => is_string($topic) && mb_strlen(trim($topic)) >= 2)
            ->filter(fn ($topic) => str_contains($haystack, mb_strtolower(trim($topic))))
            ->take(3);
        if ($topics->isNotEmpty()) {
            $score += 2 * $topics->count();
            $reasons[] = '分野の語句：' . $topics->implode('、');
        }

        return [$score, $reasons];
    }

    /**
     * @return array{category_ids: list<int>, words: list<string>, article_type: string|null}
     */
    protected function articleContext(Post|Page $article): array
    {
        $management = $this->managements->findFor($article);
        $keywords = $this->managements->keywordsFor($article);
        $categories = $article instanceof Post ? $article->categories()->get(['categories.id', 'categories.parent_id', 'categories.name']) : new Collection();
        $tags = $article instanceof Post ? $article->tags()->pluck('tags.name')->all() : [];

        return [
            'category_ids' => $this->withParents($categories->pluck('id')->all()),
            'words'        => array_values(array_filter(array_merge(
                [$article->title_raw, $management?->target_versions],
                $keywords->pluck('keyword')->all(),
                $categories->pluck('name')->all(),
                $tags,
            ))),
            'article_type' => $management?->article_type,
        ];
    }

    /**
     * カテゴリと、その親のカテゴリ（教材を親のカテゴリで登録した場合も一致させる）
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    protected function withParents(array $categoryIds): array
    {
        $ids = array_map('intval', $categoryIds);
        $current = $ids;
        for ($depth = 0; $depth < 5 && $current !== []; $depth++) {
            $current = Category::whereIn('id', $current)->whereNotNull('parent_id')->pluck('parent_id')->map(fn ($id) => (int) $id)->diff($ids)->values()->all();
            $ids = array_merge($ids, $current);
        }

        return array_values(array_unique($ids));
    }

    /**
     * AIに渡す候補の一覧（指示文用）
     *
     * @param list<array{material: Material, score: int, reasons: list<string>, used: bool, basic?: bool, usage?: int}> $candidates
     */
    public static function describe(array $candidates): string
    {
        if ($candidates === []) {
            return '（候補はありません。教材は紹介しないでください）';
        }

        return implode("\n\n", array_map(fn ($row) => MaterialDescriber::describe($row['material'])
            . "\n  - 候補にした理由：" . implode('／', $row['reasons'])
            . (($row['basic'] ?? false) ? "\n  - カテゴリの基礎の教材（記事に合う教材がないときに、この中から1件を紹介する。tech-content.md 5-6）" : '')
            . "\n  - 紹介している記事：" . ($row['usage'] ?? 0) . '件'
            . ($row['used'] ? "\n  - この記事で今使っている" : ''), $candidates));
    }
}
