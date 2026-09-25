<?php

namespace App\Models;

use App\Models\Histories\TaxonomyHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * taxonomies（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Taxonomy extends WordPressRecord
{
    public static function historyClass(): string
    {
        return TaxonomyHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'taxonomy_id';
    }

    protected function recordCasts(): array
    {
        return ['hierarchical' => 'boolean', 'types' => 'array'];
    }
}
