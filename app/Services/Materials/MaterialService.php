<?php

namespace App\Services\Materials;

use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Enums\MaterialSuggestionType;
use App\Enums\SuggestionStatus;
use App\Models\Blog;
use App\Models\Material;
use App\Models\MaterialSuggestion;
use App\Repositories\MaterialRepository;
use App\Support\AffiliateLink;
use Illuminate\Support\Facades\DB;

/**
 * 教材の登録・更新（D-30）。
 *
 * リンクを変えたら、記事の本文との照合をやり直す。記事に合う教材を選ぶための情報を変えたら、
 * この教材を使っている記事を「見直しが必要」にする（info_updated_at）。ただし、情報が空だった教材に
 * 初めて情報を入れた場合は、見直しの対象にしない（既存のリンクを登録したときに、全記事が見直しの対象になるのを避けるため）。
 */
class MaterialService
{
    /**
     * 見直しの判定に使う列（記事に合う教材を選ぶための情報）
     */
    protected const REVIEW_COLUMNS = [
        'name', 'edition', 'published_on', 'topics', 'target_versions', 'levels', 'scenes',
        'summary', 'target_readers', 'not_for', 'merits', 'cautions', 'cost_note', 'duration_note',
    ];

    public function __construct(
        protected MaterialRepository $materials,
        protected MaterialLinkService $links,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, int> $categoryIds
     */
    public function create(Blog $blog, array $attributes, array $categoryIds, ?int $userId): Material
    {
        $material = $this->materials->create($this->normalize($attributes) + [
            'blog_id'    => $blog->id,
            'status'     => MaterialStatus::Active,
            'created_by' => $userId,
        ], $categoryIds);

        $this->relink($blog);

        return $material;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, int>|null $categoryIds null なら変えない
     */
    public function update(Material $material, array $attributes, ?array $categoryIds = null): void
    {
        $attributes = $this->normalize($attributes);
        $before = $this->snapshot($material);
        $linksBefore = $material->linkKeys();

        DB::transaction(function () use ($material, $attributes, $categoryIds, $before) {
            $this->materials->update($material, $attributes, $categoryIds);
            $material->refresh();

            // 情報が変わったら、この教材を使う記事を見直しの対象にする（初めて情報を入れた場合を除く）
            $after = $this->snapshot($material);
            if ($after !== $before && $this->hasInfo($before)) {
                $material->update(['info_updated_at' => now()]);
            }
        });

        if ($material->linkKeys() !== $linksBefore) {
            $this->relink(Blog::findOrFail($material->blog_id));
        }
    }

    /**
     * AIが調べた教材の情報の案を、人が選んだ項目だけ教材に写す
     *
     * @param array<string, mixed> $values 画面で確認・修正した値（materials の列の名前）
     * @param list<string> $columns 写す列（category_ids を含む）
     */
    public function applyResearch(MaterialSuggestion $suggestion, array $values, array $columns, ?int $userId): void
    {
        $material = $suggestion->material;
        if ($material === null || $suggestion->type !== MaterialSuggestionType::Research) {
            return;
        }

        DB::transaction(function () use ($suggestion, $material, $values, $columns, $userId) {
            $attributes = array_intersect_key($values, array_flip(array_intersect($columns, Material::INFO_COLUMNS)));
            $this->update($material, $attributes, in_array('category_ids', $columns, true) ? (array) ($values['category_ids'] ?? []) : null);
            $this->materials->markSuggestionReviewed($suggestion, SuggestionStatus::Accepted, $userId);
        });
    }

    /**
     * AIが探した新しい教材の候補を、人がアフィリエイトのリンクを付けて登録する
     *
     * @param array<string, mixed> $values
     * @param array<int, int> $categoryIds
     */
    public function registerCandidate(MaterialSuggestion $suggestion, array $values, array $categoryIds, ?int $userId): Material
    {
        return DB::transaction(function () use ($suggestion, $values, $categoryIds, $userId) {
            $material = $this->create(Blog::findOrFail($suggestion->blog_id), $values + [
                'kind'                 => $suggestion->kind,
                'previous_material_id' => $suggestion->related_material_id,
                'researched_at'        => now(),
            ], $categoryIds, $userId);
            $this->materials->markSuggestionReviewed($suggestion, SuggestionStatus::Accepted, $userId, $material->id);

            return $material;
        });
    }

    /**
     * 既存の記事から検出した教材のリンクを、教材として登録する
     *
     * @param list<array{kind: MaterialKind, name: string, amazon_url: string|null, rakuten_url: string|null, affiliate_url: string|null, extra_urls: list<string>}> $detected
     * @return int 登録した数
     */
    public function registerDetected(Blog $blog, array $detected, ?int $userId): int
    {
        DB::transaction(function () use ($blog, $detected, $userId) {
            foreach ($detected as $item) {
                $this->materials->create($this->normalize([
                    'kind'          => $item['kind'],
                    'name'          => $item['name'],
                    'amazon_url'    => $item['kind'] === MaterialKind::Book ? $item['amazon_url'] : null,
                    'rakuten_url'   => $item['kind'] === MaterialKind::Book ? $item['rakuten_url'] : null,
                    'affiliate_url' => $item['kind'] !== MaterialKind::Book ? $item['affiliate_url'] : null,
                    'extra_urls'    => $item['extra_urls'],
                ]) + ['blog_id' => $blog->id, 'status' => MaterialStatus::Active, 'created_by' => $userId], []);
            }
        });

        $this->relink($blog);

        return count($detected);
    }

    /**
     * 記事の本文との照合をやり直す
     *
     * @return int 教材を使っている記事の数
     */
    public function relink(Blog $blog): int
    {
        $this->links->forget($blog->id);

        return $this->links->syncBlog($blog);
    }

    /**
     * 入力の形を整える。リンクは <a href> を含むHTMLでも受け付け、URLにする。
     * 書籍の ASIN・ISBN・Amazon と楽天の商品ページは、空ならアフィリエイトのリンクから補う（D-30-09）
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function normalize(array $attributes): array
    {
        foreach (['amazon_url', 'rakuten_url', 'affiliate_url', 'product_url', 'amazon_product_url', 'rakuten_product_url'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = AffiliateLink::extractUrl($attributes[$column]);
            }
        }
        if (array_key_exists('extra_urls', $attributes)) {
            $attributes['extra_urls'] = array_values(array_filter(array_map(fn ($url) => AffiliateLink::extractUrl($url), (array) $attributes['extra_urls']))) ?: null;
        }
        foreach (['topics', 'target_versions', 'merits', 'cautions', 'levels', 'scenes', 'sources'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = array_values(array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) $attributes[$column]), fn ($value) => filled($value))) ?: null;
            }
        }
        if (array_key_exists('isbn', $attributes)) {
            $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $attributes['isbn']));
            $attributes['isbn'] = strlen($isbn) === 10 ? AffiliateLink::isbn13FromAsin($isbn) : (strlen($isbn) === 13 ? $isbn : null);
        }

        // 商品ページの欄に入った Amazon・楽天のページは、それぞれの欄に移す（AIの案を写す場合など）
        if (filled($attributes['product_url'] ?? null)) {
            foreach (['amazon_product_url' => AffiliateLink::amazonProductUrl($attributes['product_url']), 'rakuten_product_url' => AffiliateLink::rakutenProductUrl($attributes['product_url'])] as $column => $url) {
                if ($url !== null) {
                    $attributes[$column] = filled($attributes[$column] ?? null) ? $attributes[$column] : $url;
                    $attributes['product_url'] = null;
                }
            }
        }

        $kind = $attributes['kind'] ?? null;
        if (($kind instanceof MaterialKind ? $kind : MaterialKind::tryFrom((string) $kind)) === MaterialKind::Book) {
            if (filled($attributes['amazon_url'] ?? null)) {
                $asin = AffiliateLink::asin($attributes['amazon_url']);
                $attributes['asin'] ??= $asin;
                if (blank($attributes['isbn'] ?? null) && ($isbn = AffiliateLink::isbn13FromAsin($asin)) !== null) {
                    $attributes['isbn'] = $isbn;
                }
                if (blank($attributes['amazon_product_url'] ?? null)) {
                    $attributes['amazon_product_url'] = AffiliateLink::amazonProductUrl($attributes['amazon_url']);
                }
            }
            if (filled($attributes['rakuten_url'] ?? null) && blank($attributes['rakuten_product_url'] ?? null)) {
                $attributes['rakuten_product_url'] = AffiliateLink::rakutenProductUrl($attributes['rakuten_url']);
            }
        } elseif (filled($attributes['affiliate_url'] ?? null) && array_key_exists('product_url', $attributes) && blank($attributes['product_url'])) {
            // Udemy・スクール：もしも等のリンクに遷移先が入っていれば、それを商品ページにする
            $attributes['product_url'] = AffiliateLink::landingUrl($attributes['affiliate_url']);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Material $material): array
    {
        $values = [];
        foreach (self::REVIEW_COLUMNS as $column) {
            $value = $material->getAttribute($column);
            $values[$column] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        $values['category_ids'] = $material->categories()->pluck('categories.id')->sort()->values()->all();

        return $values;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function hasInfo(array $snapshot): bool
    {
        return filled($snapshot['summary']) || filled($snapshot['topics']) || filled($snapshot['scenes']);
    }
}
