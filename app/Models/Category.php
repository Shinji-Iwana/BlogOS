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

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_categories');
    }

    /**
     * 親カテゴリからたどった名前（「親カテゴリ名 -> 子カテゴリ名」。親がなければ名前だけ。D-125-01）。
     * 親の親もたどる（親が自分を指すなど、輪になっているときは止める）
     */
    public function pathName(): string
    {
        $names = [$this->name];
        $seen = [$this->id => true];
        for ($parent = $this->parent; $parent !== null && ! isset($seen[$parent->id]); $parent = $parent->parent) {
            $seen[$parent->id] = true;
            array_unshift($names, $parent->name);
        }

        return implode(' -> ', $names);
    }
}
