<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * カテゴリの立ち上げ（D-41）。親カテゴリの下に子カテゴリを立ち上げ、記事の企画から公開までを進める。
 */
class CategoryLaunch extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['active' => '進行中', 'completed' => '完了', 'cancelled' => '中止'];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function parentCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CategoryLaunchChild::class);
    }
}
