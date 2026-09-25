<?php

namespace App\Models;

use App\Models\Histories\MediaHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * media（WordPress由来。BLOGOS_DATABASE.md 6章）
 */
class Media extends WordPressRecord
{
    protected $table = 'media';

    public static function historyClass(): string
    {
        return MediaHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'media_id';
    }

    protected function recordCasts(): array
    {
        return ['sizes' => 'array', 'wordpress_date' => 'datetime', 'wordpress_date_gmt' => 'datetime', 'wordpress_modified' => 'datetime', 'wordpress_modified_gmt' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
