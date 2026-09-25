<?php

namespace App\Models;

use App\Models\Histories\TagHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * tags（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Tag extends WordPressRecord
{
    public static function historyClass(): string
    {
        return TagHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'tag_id';
    }

    protected function recordCasts(): array
    {
        return [];
    }
}
