<?php

namespace App\Models;

use App\Enums\GoogleIndexCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 記事ごとのインデックスの登録状態（Search Console の URL 検査 API。D-37）。
 */
class GoogleIndexStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category'            => GoogleIndexCategory::class,
            'previous_category'   => GoogleIndexCategory::class,
            'last_crawl_at'       => 'datetime',
            'inspected_at'        => 'datetime',
            'category_changed_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
