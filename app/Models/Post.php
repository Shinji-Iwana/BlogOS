<?php

namespace App\Models;

use App\Models\Histories\PostHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * posts（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Post extends WordPressRecord
{
    public static function historyClass(): string
    {
        return PostHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'post_id';
    }

    protected function recordCasts(): array
    {
        return ['sticky' => 'boolean', 'wordpress_date' => 'datetime', 'wordpress_date_gmt' => 'datetime', 'wordpress_modified' => 'datetime', 'wordpress_modified_gmt' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'post_categories');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tags');
    }
}
