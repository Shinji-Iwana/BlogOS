<?php

namespace App\Services\Materials;

use App\Enums\MaterialKind;
use App\Enums\MaterialSuggestionType;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\Category;
use App\Models\MaterialSuggestion;
use App\Repositories\MaterialRepository;
use App\Services\Ai\AiException;
use App\Services\Ai\AiOutputParser;

/**
 * 教材のAI実行の結果を、人が確認する案として保存する（D-30）。AiRunService から、取り込みのトランザクションの中で呼ぶ。
 *
 * 教材そのものは変えない（人が確認して登録する）。調査した日時だけを記録する（定期チェックの判定のため）。
 */
class MaterialAiResultService
{
    public function __construct(
        protected AiOutputParser $parser,
        protected MaterialRepository $materials,
    ) {
    }

    /**
     * 教材の調査：教材の情報の案と、新しい版・関連の新しい教材の候補
     *
     * @throws AiException
     */
    public function saveResearch(AiGeneration $generation): void
    {
        $material = $generation->loadMissing('material.categories')->material ?? throw new AiException('調査の対象の教材が見つかりません。');
        $parsed = $this->parser->materialResearch((string) $generation->output);

        $data = $parsed['material'];
        $data['category_ids'] = $this->categoryIds($generation->blog_id, $data['category_ids']);
        unset($data['kind']);

        $this->materials->createSuggestion([
            'blog_id'          => $generation->blog_id,
            'type'             => MaterialSuggestionType::Research,
            'material_id'      => $material->id,
            'ai_generation_id' => $generation->id,
            'kind'             => $material->kind,
            'name'             => $data['name'] ?? $material->name,
            'data'             => $data,
            'reason'           => trim(($parsed['reason'] ?? '') . ($parsed['availability'] ? "\n販売・公開の状況：{$parsed['availability']}" : '')) ?: null,
        ]);

        foreach ($parsed['newer'] as $candidate) {
            $kind = MaterialKind::tryFrom((string) $candidate['kind']) ?? $material->kind;
            if ($this->isKnown($generation->blog_id, $kind, $candidate)) {
                continue;
            }
            $relation = ['new_edition' => '新しい版', 'successor' => '後継', 'same_author' => '同じ著者・講師の関連の教材'][$candidate['relation']];

            $this->createCandidate($generation, $kind, $candidate, "「{$material->name}」の{$relation}。" . ($candidate['reason'] ?? ''),
                in_array($candidate['relation'], ['new_edition', 'successor'], true) ? $material->id : null,
                $material->categories()->pluck('categories.id')->all());
        }

        $material->update(['researched_at' => now()]);
    }

    /**
     * 教材の候補探し：新しい教材の候補
     *
     * @throws AiException
     */
    public function saveDiscovery(AiGeneration $generation): void
    {
        $parsed = $this->parser->materialCandidates((string) $generation->output);
        $parameters = (array) $generation->parameters;
        $fallbackKind = MaterialKind::tryFrom((string) ($parameters['教材の種類の値'] ?? ''));
        $categoryId = isset($parameters['カテゴリの値']) ? (int) $parameters['カテゴリの値'] : null;

        foreach ($parsed['candidates'] as $candidate) {
            $kind = MaterialKind::tryFrom((string) $candidate['kind']) ?? $fallbackKind;
            if ($kind === null || ($fallbackKind !== null && $kind !== $fallbackKind) || $this->isKnown($generation->blog_id, $kind, $candidate)) {
                continue;
            }

            $this->createCandidate($generation, $kind, $candidate, $candidate['reason'] ?? null, null, $categoryId !== null ? [$categoryId] : []);
        }
    }

    /**
     * 記事の教材の見直し：今の教材ごとの判定と、追加の候補
     *
     * @throws AiException
     */
    public function saveReview(AiGeneration $generation): void
    {
        $article = $generation->post ?? $generation->page ?? throw new AiException('対象の記事が見つかりません。');
        $allowed = $this->materials->allForBlog($generation->blog_id)->pluck('id')->all();
        $parsed = $this->parser->materialReview((string) $generation->output, $allowed);

        $this->materials->createReview($article, [
            'ai_generation_id' => $generation->id,
            'result'           => ['current' => $parsed['current'], 'additions' => $parsed['additions']],
            'summary'          => $parsed['summary'],
        ]);
    }

    /**
     * @param array<int, int> $defaultCategoryIds AIがカテゴリを示さなかった場合のカテゴリ
     */
    protected function createCandidate(AiGeneration $generation, MaterialKind $kind, array $candidate, ?string $reason, ?int $relatedMaterialId, array $defaultCategoryIds): MaterialSuggestion
    {
        $data = $candidate;
        unset($data['kind'], $data['reason'], $data['relation']);
        $data['category_ids'] = $this->categoryIds($generation->blog_id, $data['category_ids'] ?? []) ?: $defaultCategoryIds;

        return $this->materials->createSuggestion([
            'blog_id'             => $generation->blog_id,
            'type'                => MaterialSuggestionType::Candidate,
            'related_material_id' => $relatedMaterialId,
            'ai_generation_id'    => $generation->id,
            'kind'                => $kind,
            'name'                => $candidate['name'],
            'data'                => $data,
            'reason'              => $reason,
        ]);
    }

    /**
     * 登録済みの教材か、確認待ちの候補にある教材か（ISBN・商品ページ（Amazon・楽天を含む）・名前で比べる）
     */
    protected function isKnown(int $blogId, MaterialKind $kind, array $candidate): bool
    {
        $name = $this->normalize((string) $candidate['name']);
        $pages = fn (array $values) => array_values(array_filter(array_map(fn ($url) => is_string($url) ? rtrim($url, '/') : null,
            [$values['product_url'] ?? null, $values['amazon_product_url'] ?? null, $values['rakuten_product_url'] ?? null])));
        $candidatePages = $pages($candidate);
        $matches = function (string $otherName, ?string $isbn, array $otherPages) use ($candidate, $name, $candidatePages) {
            return ($candidate['isbn'] !== null && $candidate['isbn'] === $isbn)
                || array_intersect($candidatePages, $otherPages) !== []
                || $this->normalize($otherName) === $name;
        };

        foreach ($this->materials->allForBlog($blogId) as $material) {
            if ($material->kind === $kind && $matches($material->name, $material->isbn, $pages($material->getAttributes()))) {
                return true;
            }
        }

        return MaterialSuggestion::where('blog_id', $blogId)
            ->where('type', MaterialSuggestionType::Candidate)
            ->where('status', SuggestionStatus::Pending)
            ->where('kind', $kind)
            ->get()
            ->contains(fn (MaterialSuggestion $suggestion) => $matches($suggestion->name, $suggestion->data['isbn'] ?? null, $pages((array) $suggestion->data)));
    }

    protected function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/[\s　・「」『』【】\[\]（）()]+/u', '', mb_convert_kana($name, 'as')));
    }

    /**
     * ブログにあるカテゴリのIDだけを残す
     *
     * @param array<int, int> $ids
     * @return list<int>
     */
    protected function categoryIds(int $blogId, array $ids): array
    {
        return $ids === [] ? [] : Category::where('blog_id', $blogId)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
