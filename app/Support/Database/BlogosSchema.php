<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * 記事（投稿・固定ページ）の参照の列（BLOGOS_DATABASE.md 3-5）。
     *
     * CHECK制約を付ける列には、MySQLでは ON DELETE SET NULL を使えないため CASCADE とする。
     * 投稿・固定ページは同期では物理削除しない（論理削除）ため、CASCADEが働くのはブログの完全削除のときだけである（D-20-01）。
     *
     * @param string $prefix 列名の接頭辞（例：related_ → related_post_id / related_page_id）
     */
    public static function articleReference(Blueprint $table, string $prefix = ''): void
    {
        $table->foreignId("{$prefix}post_id")->nullable()->constrained('posts')->cascadeOnDelete();
        $table->foreignId("{$prefix}page_id")->nullable()->constrained('pages')->cascadeOnDelete();
    }

    /**
     * 記事の参照に「どちらか一方だけ」または「多くとも一方」のCHECK制約を付ける（BLOGOS_DATABASE.md 3-5、11-5）。
     * Schema::create の後に呼ぶ。
     */
    public static function addArticleCheck(string $table, bool $exactlyOne, string $prefix = ''): void
    {
        $post = "{$prefix}post_id";
        $page = "{$prefix}page_id";

        $condition = $exactlyOne
            ? "(({$post} IS NULL) <> ({$page} IS NULL))"
            : "({$post} IS NULL OR {$page} IS NULL)";

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_{$prefix}article CHECK {$condition}");
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

        // 反映記録。反映記録の表より前に作った履歴テーブルには、表を作るMigrationで外部キーを付ける
        if (Schema::hasTable('wordpress_push_operations')) {
            $table->foreignId('wordpress_push_operation_id')->nullable()->constrained('wordpress_push_operations')->nullOnDelete();
        } else {
            $table->unsignedBigInteger('wordpress_push_operation_id')->nullable()->index();
        }

        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

        $table->timestamp('changed_at')->useCurrent();

        // 既定の名前がMySQLの上限（64文字）を超える場合は短い名前にする
        $indexName = strtolower("{$table->getTable()}_{$targetColumn}_changed_at_index");
        if (strlen($indexName) > 64) {
            $indexName = strtolower("{$table->getTable()}_target_changed_at_index");
        }
        $table->index([$targetColumn, 'changed_at'], $indexName);
    }
}
