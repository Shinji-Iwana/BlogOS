<?php

namespace App\Services\Topics;

use App\Enums\KeywordType;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\ArticleKeyword;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\TopicSuggestion;
use App\Services\Ai\AiException;
use App\Services\Ai\AiOutputParser;

/**
 * 記事の企画の結果を、人が確認する案として保存する（D-40）。AiRunService から、取り込みのトランザクションの中で呼ぶ。
 *
 * 同じカテゴリ・同じ種類の確認待ちの案は、新しい案に置き換える。既存の記事・作業中の編集案と重なる可能性がある案には、その旨を残す。
 */
class TopicPlanningResultService
{
    /**
     * @var array{keywords: array<string, string>, titles: array<string, string>}|null 重複の確認に使う、既存の記事のキーワードとタイトル（正規化した値 => 元の値）
     */
    protected ?array $existing = null;

    public function __construct(
        protected AiOutputParser $parser,
    ) {
    }

    /**
     * @throws AiException
     */
    public function save(AiGeneration $generation): void
    {
        $parsed = $this->parser->topicPlanning((string) $generation->output);
        $parameters = (array) $generation->parameters;
        $category = ctype_digit((string) ($parameters['カテゴリの値'] ?? '')) ? Category::where('blog_id', $generation->blog_id)->find((int) $parameters['カテゴリの値']) : null;
        if ($category === null) {
            throw new AiException('企画の対象のカテゴリが見つかりません。');
        }

        $unit = ($parameters['企画の単位の値'] ?? 'articles') === 'categories' ? 'category' : 'article';
        $items = $unit === 'category' ? $parsed['categories'] : $parsed['articles'];
        if ($items === []) {
            throw new AiException('出力に案がありませんでした（' . ($unit === 'category' ? 'categories' : 'articles') . ' が空です）。');
        }

        // 同じカテゴリ・同じ種類の確認待ちの案は、新しい案に置き換える
        TopicSuggestion::where('blog_id', $generation->blog_id)->where('category_id', $category->id)->where('type', $unit)
            ->whereNull('parent_suggestion_id')->where('status', SuggestionStatus::Pending)->update(['status' => SuggestionStatus::Superseded->value]);

        $this->existing = null;
        foreach ($items as $item) {
            if ($unit === 'article') {
                $this->createArticle($generation, $category->id, null, $item);

                continue;
            }

            $suggestion = TopicSuggestion::create([
                'blog_id'          => $generation->blog_id,
                'ai_generation_id' => $generation->id,
                'type'             => 'category',
                'category_id'      => $category->id,
                'title'            => $item['name'],
                'slug'             => $item['slug'],
                'scope'            => $item['scope'],
                'roadmap_step'     => $item['position'],
                'priority'         => $item['priority'],
                'reason'           => $item['reason'],
                'sources'          => $item['sources'],
                'duplicate_note'   => Category::where('blog_id', $generation->blog_id)->existing()->where(fn ($q) => $q->where('name', $item['name'])->when($item['slug'], fn ($q2) => $q2->orWhere('slug', $item['slug'])))->exists()
                    ? '同じ名前・スラッグのカテゴリが既にあります。' : null,
                'status'           => SuggestionStatus::Pending,
            ]);
            foreach ($item['first_articles'] as $article) {
                $this->createArticle($generation, $category->id, $suggestion->id, $article);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function createArticle(AiGeneration $generation, int $categoryId, ?int $parentId, array $item): void
    {
        TopicSuggestion::create([
            'blog_id'              => $generation->blog_id,
            'ai_generation_id'     => $generation->id,
            'type'                 => 'article',
            'category_id'          => $categoryId,
            'parent_suggestion_id' => $parentId,
            'title'                => $item['title'],
            'main_keyword'         => $item['main_keyword'],
            'sub_keywords'         => $item['sub_keywords'],
            'search_intent'        => $item['search_intent'],
            'article_type'         => $item['article_type'],
            'article_subtype'      => $item['article_subtype'],
            'roadmap_step'         => $item['roadmap_step'],
            'priority'             => $item['priority'],
            'reason'               => $item['reason'],
            'sources'              => $item['sources'],
            'duplicate_note'       => $this->duplicateNote($generation->blog_id, $item['title'], $item['main_keyword']),
            'status'               => SuggestionStatus::Pending,
        ]);
    }

    /**
     * 既存の記事・作業中の新しい記事と重なる可能性（メインキーワードが同じ、またはタイトルにメインキーワードの語がすべて含まれる）
     */
    protected function duplicateNote(int $blogId, string $title, ?string $mainKeyword): ?string
    {
        $existing = $this->existing ??= $this->loadExisting($blogId);
        $keyword = $this->normalize((string) $mainKeyword);

        if ($keyword !== '' && isset($existing['keywords'][$keyword])) {
            return "メインキーワードが同じ記事があります：「{$existing['keywords'][$keyword]}」";
        }
        if ($keyword !== '') {
            $tokens = array_filter(preg_split('/[\s　]+/u', mb_strtolower(trim((string) $mainKeyword))) ?: [], fn ($token) => mb_strlen($token) >= 2);
            foreach ($existing['titles'] as $normalized => $original) {
                if ($tokens !== [] && collect($tokens)->every(fn ($token) => str_contains($normalized, $this->normalize($token)))) {
                    return "内容が重なる可能性のある記事があります：「{$original}」";
                }
            }
        }
        if (isset($existing['titles'][$this->normalize($title)])) {
            return '同じタイトルの記事があります。';
        }

        return null;
    }

    /**
     * @return array{keywords: array<string, string>, titles: array<string, string>}
     */
    protected function loadExisting(int $blogId): array
    {
        $titles = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            foreach ($modelClass::where('blog_id', $blogId)->existing()->pluck('title_raw') as $title) {
                $titles[$this->normalize((string) $title)] = (string) $title;
            }
        }
        foreach (ArticleDraft::where('blog_id', $blogId)->whereNull('post_id')->whereNull('page_id')->active()->pluck('title_raw') as $title) {
            $titles[$this->normalize((string) $title)] = "{$title}（作業中の新しい記事）";
        }

        $keywords = [];
        foreach (ArticleKeyword::with(['post:id,title_raw', 'page:id,title_raw'])->where('blog_id', $blogId)->where('keyword_type', KeywordType::Main)->get() as $row) {
            $keywords[$this->normalize($row->keyword)] = (string) (($row->post ?? $row->page)?->title_raw ?? $row->keyword);
        }

        return ['keywords' => $keywords, 'titles' => $titles];
    }

    protected function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s　]+/u', '', $value));
    }
}
