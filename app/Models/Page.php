<?php

namespace App\Models;

use App\Models\Histories\PageHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * pages（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Page extends WordPressRecord
{
    public static function historyClass(): string
    {
        return PageHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'page_id';
    }

    protected function recordCasts(): array
    {
        return ['wordpress_date' => 'datetime', 'wordpress_date_gmt' => 'datetime', 'wordpress_modified' => 'datetime', 'wordpress_modified_gmt' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }
}
