<?php

namespace App\Models;

use App\Models\Histories\CustomContentHistory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * カスタム投稿タイプの内容（BLOGOS_DATABASE.md 6-8）。同期と閲覧だけの対象。
 */
class CustomContent extends WordPressRecord
{
    public static function historyClass(): string
    {
        return CustomContentHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'custom_content_id';
    }

    protected function recordCasts(): array
    {
        return ['wordpress_date' => 'datetime', 'wordpress_date_gmt' => 'datetime', 'wordpress_modified' => 'datetime', 'wordpress_modified_gmt' => 'datetime'];
    }

    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(CustomTerm::class, 'custom_content_terms');
    }
}
