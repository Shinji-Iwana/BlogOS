<?php

namespace App\Models;

use App\Models\Histories\TypeHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * types（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Type extends WordPressRecord
{
    public static function historyClass(): string
    {
        return TypeHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'type_id';
    }

    protected function recordCasts(): array
    {
        return ['hierarchical' => 'boolean', 'taxonomies' => 'array'];
    }
}
