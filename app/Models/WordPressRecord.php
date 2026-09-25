<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WordPress由来のテーブルに共通の定義（BLOGOS_DATABASE.md 3-6）。
 *
 * 各テーブルの列は、同期（App\Services\Sync）が書き込む。BlogOS独自の情報は持たない（D-01-11）。
 */
abstract class WordPressRecord extends Model
{
    protected $guarded = ['id'];

    /**
     * 履歴のModelのクラス名
     */
    abstract public static function historyClass(): string;

    /**
     * 履歴のテーブルで、このレコードを指す列名
     */
    abstract public static function historyForeignKey(): string;

    protected function casts(): array
    {
        return array_merge([
            'synced_at'            => 'datetime',
            'wordpress_deleted_at' => 'datetime',
        ], $this->recordCasts());
    }

    /**
     * テーブルごとの型変換
     */
    protected function recordCasts(): array
    {
        return [];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /**
     * WordPress側で完全削除されていないもの（通常の一覧に表示する。D-09-02）
     */
    public function scopeExisting(Builder $query): void
    {
        $query->whereNull('wordpress_deleted_at');
    }
}
