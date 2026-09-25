<?php

namespace App\Models;

use App\Models\Histories\StatusHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * statuses（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Status extends WordPressRecord
{
    public static function historyClass(): string
    {
        return StatusHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'status_id';
    }

    protected function recordCasts(): array
    {
        return ['public' => 'boolean', 'queryable' => 'boolean', 'show_in_list' => 'boolean'];
    }
}
