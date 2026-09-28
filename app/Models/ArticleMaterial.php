<?php

namespace App\Models;

use App\Enums\ArticleMaterialSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 記事で使っている教材（D-30）。
 */
class ArticleMaterial extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source'      => ArticleMaterialSource::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function article(): Post|Page|null
    {
        return $this->post ?? $this->page;
    }

    /**
     * 見直しが必要な理由（なければ null）。教材を使わなくなった、新しい版を登録した、教材の情報・記事が見直しの後に更新された。
     *
     * 「使わない」にした教材は、記事から外すまで（本文からリンクがなくなるまで）見直しの対象のままにする
     */
    public function reviewReason(): ?string
    {
        $material = $this->material;
        $article = $this->article();

        return match (true) {
            $material === null                  => null,
            ! $material->isActive()             => '教材を「使わない」にした',
            $material->successors->contains(fn (Material $next) => $this->reviewed_at === null || $next->created_at->gte($this->reviewed_at)) => '新しい版を登録した',
            $this->reviewed_at === null         => '見直していない',
            $material->info_updated_at !== null && $material->info_updated_at->gt($this->reviewed_at) => '教材の情報を更新した',
            $article?->wordpress_modified_gmt !== null && $article->wordpress_modified_gmt->gt($this->reviewed_at) => '記事を更新した',
            default                             => null,
        };
    }
}
