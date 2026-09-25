<?php

namespace App\Models;

use App\Enums\WorkStatus;
use App\Models\Histories\ArticleManagementHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 記事ごとのBlogOS独自の管理情報。記事1件につき1行（BLOGOS_DATABASE.md 9-2、D-08-02）。
 */
class ArticleManagement extends Model
{
    // 「management」は数えられない名詞として扱われ、テーブル名が単数形になるため明示する
    protected $table = 'article_managements';

    protected $guarded = ['id'];

    protected $attributes = [
        'work_status' => 'not_started',
    ];

    /**
     * 履歴の対象の列
     */
    public const TRACKED_COLUMNS = ['article_type', 'article_subtype', 'main_search_intent', 'sub_search_intents', 'work_status', 'memo'];

    protected function casts(): array
    {
        return [
            'sub_search_intents' => 'array',
            'work_status'        => WorkStatus::class,
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ArticleManagementHistory::class);
    }
}
