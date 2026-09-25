<?php

namespace App\Models;

use App\Models\Histories\CategoryHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * categories（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Category extends WordPressRecord
{
    public static function historyClass(): string
    {
        return CategoryHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'category_id';
    }

    protected function recordCasts(): array
    {
        return [];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }
}
