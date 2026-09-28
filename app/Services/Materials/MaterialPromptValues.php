<?php

namespace App\Services\Materials;

use App\Clients\Rakuten\RakutenBooksClient;
use App\Clients\Rakuten\RakutenException;
use App\Enums\AiMode;
use App\Enums\MaterialKind;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\MaterialRepository;
use App\Support\AffiliateLink;

/**
 * 教材のAI実行（調査・候補探し・見直し）の指示文に入れる値（D-30）。PromptBuilder から使う。
 */
class MaterialPromptValues
{
    public function __construct(
        protected MaterialRepository $materials,
        protected MaterialMatcher $matcher,
    ) {
    }

    /**
     * @param array<string, string|null> $parameters
     * @return array<string, string>
     */
    public function values(AiMode $mode, Blog $blog, Post|Page|null $article, ?Material $material, array $parameters, bool $webSearch): array
    {
        $kind = $material?->kind ?? MaterialKind::tryFrom((string) ($parameters['教材の種類の値'] ?? ''));
        $category = isset($parameters['カテゴリの値']) ? Category::where('blog_id', $blog->id)->find((int) $parameters['カテゴリの値']) : null;

        return match ($mode) {
            AiMode::MaterialResearch => [
                'research_method'      => $this->researchMethod($webSearch),
                'material_info'        => $material ? $this->materialInfo($material) : '（なし）',
                'material_sources'     => $material && $material->kind === MaterialKind::Book ? $this->rakutenForMaterial($material) : '（なし）',
                'material_options'     => $this->options($blog),
                'registered_materials' => $this->registered($blog, $material?->kind),
            ],
            AiMode::MaterialDiscovery => [
                'research_method'      => $this->researchMethod($webSearch),
                'discovery_target'     => $this->discoveryTarget($blog, $category, $kind, $parameters),
                'material_sources'     => $kind === MaterialKind::Book && $category ? $this->rakutenForCategory($category, $parameters['探す語句'] ?? null) : '（なし）',
                'material_options'     => $this->options($blog),
                'registered_materials' => $this->registered($blog, $kind),
            ],
            AiMode::MaterialReview => [
                'article_materials'   => $article ? $this->articleMaterials($article) : '（なし）',
                'material_candidates' => MaterialMatcher::describe($this->matcher->candidates($blog, $article)),
            ],
            default => [],
        };
    }

    protected function researchMethod(bool $webSearch): string
    {
        return $webSearch
            ? 'Web検索を使えます。出版社・販売サイト・Udemyの講座ページ・スクールの公式サイトなどで確認し、確認したページのURLを sources に入れてください。'
            : 'Web検索を使える場合は使って確認し、確認したページのURLを sources に入れてください。使えない場合は、下の「確認できた情報」と「人が提供した情報」だけを根拠にしてください。';
    }

    protected function materialInfo(Material $material): string
    {
        $material->loadMissing(['categories', 'previous', 'successors']);
        $lines = [MaterialDescriber::describe($material)];

        // アフィリエイトのリンクの遷移先（商品ページ）。AIの調査の手がかり
        foreach ($material->affiliateLinks() as $label => $url) {
            $inner = AffiliateLink::innerUrl($url);
            if ($inner !== null && $inner !== $url) {
                $lines[] = "  - 紹介リンク（{$label}）の遷移先：{$inner}";
            }
        }
        if ($material->asin && ! $material->isbn && ($isbn = AffiliateLink::isbn13FromAsin($material->asin))) {
            $lines[] = "  - ASIN {$material->asin} から計算したISBN：{$isbn}";
        }

        return implode("\n", $lines);
    }

    /**
     * 書籍：同じ本（ISBN）と、同じ著者の新しい本（新しい版・関連の本の確認）
     */
    protected function rakutenForMaterial(Material $material): string
    {
        $client = RakutenBooksClient::fromConfig();
        if (! $client->isConfigured()) {
            return '（楽天ブックスAPIは設定されていないため、使っていません）';
        }

        $isbn = $material->isbn ?: AffiliateLink::isbn13FromAsin($material->asin ?: AffiliateLink::asin($material->amazon_url));
        $sections = [];

        try {
            if ($isbn) {
                $sections[] = "### 楽天ブックスAPI：ISBN {$isbn} の本\n\n" . $this->books($client->byIsbn($isbn));
            }
            $title = $this->titleCore($material->name);
            $author = $material->creator ? trim(preg_split('/[\/／、,]/u', $material->creator)[0]) : null;
            $sections[] = "### 楽天ブックスAPI：書名に「{$title}」を含む本" . ($author ? "（著者：{$author}）" : '') . "（新しい順）\n\n"
                . $this->books($client->byTitleAndAuthor($title, $author));
            if ($author) {
                $sections[] = "### 楽天ブックスAPI：著者「{$author}」の本（新しい順）\n\n" . $this->books($client->byTitleAndAuthor(null, $author));
            }
        } catch (RakutenException $e) {
            $sections[] = "（楽天ブックスAPIの取得に失敗しました：{$e->getMessage()}）";
        }

        return implode("\n\n", $sections);
    }

    protected function rakutenForCategory(Category $category, ?string $words): string
    {
        $client = RakutenBooksClient::fromConfig();
        if (! $client->isConfigured()) {
            return '（楽天ブックスAPIは設定されていないため、使っていません）';
        }

        $title = filled($words) ? trim((string) $words) : $this->titleCore($category->name);

        try {
            return "### 楽天ブックスAPI：書名に「{$title}」を含む、PC・システム開発の本（売れている順）\n\n" . $this->books($client->computerBooks($title));
        } catch (RakutenException $e) {
            return "（楽天ブックスAPIの取得に失敗しました：{$e->getMessage()}）";
        }
    }

    /**
     * @param list<array{title: string, author: string, publisher: string, sales_date: string, isbn: string, caption: string, url: string}> $books
     */
    protected function books(array $books): string
    {
        if ($books === []) {
            return '（該当する本はありませんでした）';
        }

        return implode("\n", array_map(fn ($book) => "- {$book['title']}／{$book['author']}／{$book['publisher']}／発売日 {$book['sales_date']}／ISBN {$book['isbn']}／{$book['url']}"
            . ($book['caption'] !== '' ? "\n  - 紹介文：" . str_replace("\n", ' ', $book['caption']) : ''), $books));
    }

    /**
     * 書名から、版・かっこ書き・副題を除いた部分（同じ本の別の版を探すため）
     */
    protected function titleCore(string $name): string
    {
        $core = preg_replace(['/[【\[（(].*?[】\]）)]/u', '/第?\s*[0-9０-９一二三四五六七八九十]+\s*版/u', '/改訂(新)?版?|新版|最新版/u', '/[ 　]*[—\-–:：~〜].*$/u'], ' ', $name);
        $core = trim(preg_replace('/\s+/u', ' ', (string) $core));

        return mb_substr($core !== '' ? $core : $name, 0, 60);
    }

    protected function options(Blog $blog): string
    {
        $lines = ['対象のレベル（levels）：'];
        foreach (Material::LEVELS as $value => $label) {
            $lines[] = "- {$value}：{$label}";
        }
        $lines[] = '向いている場面（scenes）：';
        foreach (Material::SCENES as $value => $label) {
            $lines[] = "- {$value}：{$label}";
        }
        $lines[] = 'カテゴリ（category_ids。ブログのカテゴリのID）：';
        $categories = Category::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->orderBy('name')->get(['id', 'name', 'parent_id']);
        $names = $categories->pluck('name', 'id');
        foreach ($categories as $category) {
            $lines[] = "- {$category->id}：{$category->name}" . ($category->parent_id && isset($names[$category->parent_id]) ? "（親：{$names[$category->parent_id]}）" : '');
        }

        return implode("\n", $lines);
    }

    protected function registered(Blog $blog, ?MaterialKind $kind): string
    {
        $materials = $this->materials->allForBlog($blog->id)->filter(fn (Material $material) => $kind === null || $material->kind === $kind);
        if ($materials->isEmpty()) {
            return '（なし）';
        }

        return $materials->map(fn (Material $material) => "- 教材ID {$material->id}：{$material->kind->label()}「{$material->name}」"
            . ($material->edition ? "（{$material->edition}）" : '') . ($material->isbn ? " ISBN {$material->isbn}" : '')
            . ($material->creator ? "／{$material->creator}" : ''))->implode("\n");
    }

    protected function discoveryTarget(Blog $blog, ?Category $category, ?MaterialKind $kind, array $parameters): string
    {
        $lines = [
            '- 教材の種類：' . ($kind?->label() ?? '書籍・Udemy・スクールのすべて'),
            '- 探す数：' . ($parameters['探す数'] ?? '5') . '件まで',
        ];

        if ($category !== null) {
            $parent = $category->parent_id ? Category::find($category->parent_id) : null;
            $lines[] = "- カテゴリ：{$category->name}（ID {$category->id}）" . ($parent ? "（親：{$parent->name}）" : '');
            if (filled($category->description ?? null)) {
                $lines[] = '- カテゴリの説明：' . strip_tags((string) $category->description);
            }
            $titles = $category->posts()->where('posts.status', 'publish')->whereNull('posts.wordpress_deleted_at')->limit(40)->pluck('title_raw');
            if ($titles->isNotEmpty()) {
                $lines[] = "- このカテゴリの記事（{$titles->count()}件まで）：";
                foreach ($titles as $title) {
                    $lines[] = "  - {$title}";
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function articleMaterials(Post|Page $article): string
    {
        $records = $this->materials->forArticle($article);
        if ($records->isEmpty()) {
            return '（この記事では、教材を使っていません）';
        }

        return $records->map(fn ($record) => MaterialDescriber::describe($record->material)
            . (($reason = $record->reviewReason()) !== null ? "\n  - 見直しの理由：{$reason}" : ''))->implode("\n\n");
    }
}
