<?php

use App\Support\Database\BlogosSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 編集案（BLOGOS_DATABASE.md 9-1）と反映記録（10-4）。
     * あわせて、段階3で外部キーを付けずに作った「反映記録・編集案」への参照に外部キーを付ける。
     */
    public function up(): void
    {
        Schema::create('article_drafts', function (Blueprint $table) {
            $table->id();

            // 外部に渡す識別子（WordPressの投稿メタ _blogos_draft_id に書き込む。D-01-12）
            $table->uuid('uuid')->unique();

            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 対象の記事（既存記事の改修の場合。新規記事では両方NULL）
            BlogosSchema::articleReference($table);

            // 記事の種類（post / page）。既存記事の改修でも設定する
            $table->string('target_type', 10);

            // 編集の起点にしたWordPressの版（競合チェックに使う。D-01-08）
            $table->dateTime('base_wordpress_modified_gmt')->nullable();

            // 内容（反映時に送る値）
            $table->text('title_raw')->nullable();
            $table->longText('content_raw')->nullable();
            $table->text('excerpt_raw')->nullable();
            $table->string('slug', 200)->nullable();
            $table->string('status', 20)->nullable(); // 反映時に設定するステータス
            $table->json('wordpress_category_ids')->nullable(); // 投稿のみ
            $table->json('wordpress_tag_ids')->nullable(); // 投稿のみ
            $table->unsignedBigInteger('wordpress_featured_media_id')->nullable();

            // 状態（App\Enums\DraftState）
            $table->string('state', 20)->default('editing');

            // 作成元（App\Enums\DraftOrigin）とAIの関与（D-07-06）
            $table->string('origin', 10)->default('human');
            $table->unsignedBigInteger('ai_generation_id')->nullable()->index(); // 段階6で外部キーを付ける
            $table->boolean('human_edited')->default(false);
            $table->decimal('edit_ratio', 5, 4)->nullable();

            // 改修範囲（D-06-01）
            $table->string('revision_scope', 20)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'state']);
        });
        BlogosSchema::addArticleCheck('article_drafts', exactlyOne: false);

        Schema::create('article_draft_histories', function (Blueprint $table) {
            BlogosSchema::history($table, 'article_draft_id', 'article_drafts');
        });

        Schema::create('wordpress_push_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 記事の反映の場合
            $table->foreignId('article_draft_id')->nullable()->constrained('article_drafts')->cascadeOnDelete();

            // 対象（多くとも1つ。新規作成ではすべてNULL）。CHECK制約のためCASCADE（D-20-01）
            BlogosSchema::articleReference($table);
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained('tags')->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->cascadeOnDelete();

            // App\Enums\PushResourceType / PushOperationType / PushState
            $table->string('resource_type', 20);
            $table->string('operation', 20);
            $table->string('state', 20)->default('pending');

            // 送信内容の要約（認証情報は含めない）と、画面を開いた時点の値（D-15-05）
            $table->json('request_summary')->nullable();
            $table->json('base_values')->nullable();

            // WordPressの応答（受信直後に最優先で保存する）
            $table->unsignedBigInteger('wordpress_id')->nullable();
            $table->dateTime('response_modified_gmt')->nullable();

            // エラー時のHTTPステータスと応答本文（全文。D-10-03）
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->longText('error_body')->nullable();
            $table->text('message')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('wp_succeeded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'state']);
        });
        DB::statement('ALTER TABLE wordpress_push_operations ADD CONSTRAINT chk_wordpress_push_operations_target CHECK ('
            . '(post_id IS NOT NULL) + (page_id IS NOT NULL) + (category_id IS NOT NULL) + (tag_id IS NOT NULL) + (media_id IS NOT NULL) <= 1)');

        // 段階3で外部キーを付けずに作った列
        foreach (self::HISTORY_TABLES as $historyTable) {
            Schema::table($historyTable, function (Blueprint $table) {
                $table->foreign('wordpress_push_operation_id')->references('id')->on('wordpress_push_operations')->nullOnDelete();
            });
        }

        Schema::table('sync_issues', function (Blueprint $table) {
            $table->foreign('wordpress_push_operation_id')->references('id')->on('wordpress_push_operations')->nullOnDelete();
            $table->foreign('article_draft_id')->references('id')->on('article_drafts')->nullOnDelete();
        });
    }

    /**
     * 反映記録への参照を持つ履歴テーブル（段階3までに作ったもの）
     */
    protected const HISTORY_TABLES = [
        'blog_histories', 'blog_setting_histories', 'article_draft_histories',
        'status_histories', 'type_histories', 'taxonomy_histories',
        'author_histories', 'category_histories', 'tag_histories', 'media_histories',
        'page_histories', 'post_histories',
    ];

    public function down(): void
    {
        // 途中で失敗した後に再実行できるよう、存在する外部キーだけを外す
        $this->dropForeignIfExists('sync_issues', 'wordpress_push_operation_id');
        $this->dropForeignIfExists('sync_issues', 'article_draft_id');

        foreach (self::HISTORY_TABLES as $historyTable) {
            $this->dropForeignIfExists($historyTable, 'wordpress_push_operation_id');
        }

        Schema::dropIfExists('wordpress_push_operations');
        Schema::dropIfExists('article_draft_histories');
        Schema::dropIfExists('article_drafts');
    }

    protected function dropForeignIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreignKey['name']));
            }
        }
    }
};
