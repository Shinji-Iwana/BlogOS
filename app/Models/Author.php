<?php

namespace App\Models;

use App\Models\Histories\AuthorHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * authors（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Author extends WordPressRecord
{
    public static function historyClass(): string
    {
        return AuthorHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'author_id';
    }

    protected function recordCasts(): array
    {
        return ['avatar_urls' => 'array', 'roles' => 'array'];
    }
}
