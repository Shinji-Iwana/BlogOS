<?php

namespace App\Models;

use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Support\AffiliateLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 収益用の教材（書籍・Udemy・スクール）。D-30。
 *
 * 記事に合う教材を選ぶための情報（分野・レベル・向いている場面など）は、AIが調査し、人が確認して登録する。
 */
class Material extends Model
{
    protected $guarded = ['id'];

    /**
     * 対象のレベル（levels の値 => 表示名）
     */
    public const LEVELS = [
        'intro'        => '入門（プログラミング未経験）',
        'beginner'     => '初心者',
        'intermediate' => '中級',
        'practical'    => '実務',
    ];

    /**
     * 紹介する場面（scenes の値 => 表示名）。品質基準 si-note/tech-content.md 5-2〜5-4 の「紹介する場面」「紹介の対象」
     */
    public const SCENES = [
        'systematic'    => '基礎から体系的に学びたい',
        'practical'     => '実務レベルまで学びたい',
        'certification' => '資格の取得を目指す',
        'deep_dive'     => '記事では説明しきれない内容まで学びたい',
        'hands_on'      => 'ハンズオン形式で学びたい',
        'watch_dev'     => '実際の開発を見ながら学びたい',
        'struggling'    => '独学で挫折している',
        'career'        => '転職を目指している',
        'short_term'    => '短期間で学習したい',
        'mentor'        => 'メンターのサポートが必要',
    ];

    /**
     * 案（material_suggestions.data）から教材に写せる列
     */
    public const INFO_COLUMNS = [
        'name', 'product_url', 'amazon_product_url', 'rakuten_product_url', 'isbn', 'asin', 'creator', 'publisher', 'edition', 'published_on',
        'topics', 'target_versions', 'levels', 'scenes', 'summary', 'target_readers', 'not_for', 'merits', 'cautions',
        'cost_note', 'duration_note', 'cost_checked_on', 'sources',
    ];

    protected function casts(): array
    {
        return [
            'kind'            => MaterialKind::class,
            'status'          => MaterialStatus::class,
            'extra_urls'      => 'array',
            'topics'          => 'array',
            'target_versions' => 'array',
            'levels'          => 'array',
            'scenes'          => 'array',
            'merits'          => 'array',
            'cautions'        => 'array',
            'sources'         => 'array',
            'published_on'    => 'date',
            'cost_checked_on' => 'date',
            'info_updated_at' => 'datetime',
            'researched_at'   => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'material_categories');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'previous_material_id');
    }

    /**
     * 次の版（この教材を前の版とする教材）
     */
    public function successors(): HasMany
    {
        return $this->hasMany(Material::class, 'previous_material_id');
    }

    public function articleMaterials(): HasMany
    {
        return $this->hasMany(ArticleMaterial::class);
    }

    public function isActive(): bool
    {
        return $this->status === MaterialStatus::Active;
    }

    /**
     * 記事に置くリンク（アフィリエイト）。書籍は Amazon と楽天
     *
     * @return array<string, string> 表示名 => URL
     */
    public function affiliateLinks(): array
    {
        return array_filter([
            'Amazon'  => $this->amazon_url,
            '楽天'    => $this->rakuten_url,
            'リンク'  => $this->affiliate_url,
        ], fn ($url) => filled($url));
    }

    /**
     * 記事の本文との照合に使う、リンクの識別子（AffiliateLink::key）
     *
     * @return list<string>
     */
    public function linkKeys(): array
    {
        $urls = array_merge(array_values($this->affiliateLinks()), (array) $this->extra_urls);

        return array_values(array_unique(array_filter(array_map(fn ($url) => AffiliateLink::key((string) $url), $urls))));
    }
}
