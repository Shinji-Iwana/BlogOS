<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-41：カテゴリの立ち上げ（親カテゴリの下に子カテゴリを立ち上げ、記事の企画 → 記事の編集案 → 子ロードマップ → 公開までを進める）。
     */
    public function up(): void
    {
        Schema::create('category_launches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->foreignId('parent_category_id')->constrained('categories')->cascadeOnDelete();
            // 状態（active：進行中 / completed：完了 / cancelled：中止）
            $table->string('status', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 立ち上げる子カテゴリ（既存の子カテゴリ、または採用した子カテゴリの案）
        Schema::create('category_launch_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_launch_id')->constrained('category_launches')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('topic_suggestion_id')->nullable()->constrained('topic_suggestions')->nullOnDelete();
            $table->string('name', 255);
            $table->string('slug', 100)->nullable();
            $table->text('scope')->nullable();
            // 子ロードマップの編集案
            $table->foreignId('roadmap_draft_id')->nullable()->constrained('article_drafts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('topic_suggestions', function (Blueprint $table) {
            // 立ち上げる子カテゴリの記事の案と、その記事の編集案・作成中の AI 実行
            $table->foreignId('launch_child_id')->nullable()->after('parent_suggestion_id')->constrained('category_launch_children')->nullOnDelete();
            $table->foreignId('article_draft_id')->nullable()->after('launch_child_id')->constrained('article_drafts')->nullOnDelete();
            $table->foreignId('article_generation_id')->nullable()->after('article_draft_id')->constrained('ai_generations')->nullOnDelete();
        });

        Schema::table('article_drafts', function (Blueprint $table) {
            // 立ち上げる子カテゴリの記事・子ロードマップ（公開のときに、カテゴリ・親のページを設定する）
            $table->foreignId('category_launch_child_id')->nullable()->after('auto_reason')->constrained('category_launch_children')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_launch_child_id');
        });
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_generation_id');
            $table->dropConstrainedForeignId('article_draft_id');
            $table->dropConstrainedForeignId('launch_child_id');
        });
        Schema::dropIfExists('category_launch_children');
        Schema::dropIfExists('category_launches');
    }
};
