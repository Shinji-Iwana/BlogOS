<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migrationで共通に使う列の定義（BLOGOS_DATABASE.md 3-6・8-2）。
 */
class BlogosSchema
{
    /**
     * WordPress由来のテーブルに共通の列（BLOGOS_DATABASE.md 3-6）
     *
     * @param bool $numericId WordPressの数値IDを持つ（statuses・types・taxonomies は slug で識別するため false）
     * @param bool $hasModified WordPressが更新日時を返す（投稿・固定ページ・メディア）
     */
    public static function wordpressOrigin(Blueprint $table, bool $numericId = true, bool $hasModified = false): void
    {
        $table->id();
        $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

        if ($numericId) {
            $table->unsignedBigInteger('wordpress_id');
            $table->unique(['blog_id', 'wordpress_id']);
        }

        if ($hasModified) {
            $table->dateTime('wordpress_date')->nullable();
            $table->dateTime('wordpress_date_gmt')->nullable();
            $table->dateTime('wordpress_modified')->nullable();
            $table->dateTime('wordpress_modified_gmt')->nullable()->index();
        }

        // 最後にWordPressと照合した日時
        $table->timestamp('synced_at')->nullable();

        // WordPress側で完全削除を検知した日時（Laravelの deleted_at は使わない。D-09-02）
        $table->timestamp('wordpress_deleted_at')->nullable();

        $table->timestamps();
    }

    /**
     * 履歴の共通の列（BLOGOS_DATABASE.md 8-2）
     *
     * @param string $targetColumn 対象レコードの列名（例：post_id）
     * @param string $targetTable  対象のテーブル（例：posts）
     */
    public static function history(Blueprint $table, string $targetColumn, string $targetTable): void
    {
        $table->id();
        $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
        $table->foreignId($targetColumn)->constrained($targetTable)->cascadeOnDelete();

        // 同時に起きた変更をまとめるID
        $table->uuid('change_set_id')->index();

        // 変更した項目（列名。関連は categories / tags 等。作成・削除・復活は __created / __deleted / __restored）
        $table->string('field', 100);

        $table->longText('old_value')->nullable();
        $table->longText('new_value')->nullable();

        // 変更元（App\Enums\ChangeSource）
        $table->string('source', 50);

        $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();

        // 反映記録（段階4で表を作るときに外部キーを付ける）
        $table->unsignedBigInteger('wordpress_push_operation_id')->nullable()->index();

        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

        $table->timestamp('changed_at')->useCurrent();

        $table->index([$targetColumn, 'changed_at']);
    }
}
