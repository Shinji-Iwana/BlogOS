<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-40：記事の企画（AIが出した、まだ記事にしていない内容・足りない子カテゴリの案）。人が確認して採用する。
     */
    public function up(): void
    {
        Schema::create('topic_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();

            // 種類（article：記事の案 / category：子カテゴリの案）。記事の案は category_id のカテゴリの記事、
            // 子カテゴリの案は category_id（親カテゴリ）の子。子カテゴリの案の最初の記事の案は parent_suggestion_id で結ぶ
            $table->string('type', 10);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('parent_suggestion_id')->nullable()->constrained('topic_suggestions')->cascadeOnDelete();

            // 記事の案：タイトル案・キーワード・検索意図・記事種類・ロードマップのステップ。子カテゴリの案：名前・スラッグ・範囲
            $table->string('title', 255);
            $table->string('slug', 100)->nullable();
            $table->string('main_keyword', 191)->nullable();
            $table->json('sub_keywords')->nullable();
            $table->text('search_intent')->nullable();
            $table->string('article_type', 50)->nullable();
            $table->string('article_subtype', 50)->nullable();
            $table->string('roadmap_step', 255)->nullable();
            $table->text('scope')->nullable();
            $table->string('priority', 10)->nullable();
            $table->text('reason')->nullable();
            $table->json('sources')->nullable();
            // 既存の記事・作業中の編集案と重なる可能性（BlogOS が機械的に調べた結果）
            $table->text('duplicate_note')->nullable();

            // 状態（App\Enums\SuggestionStatus）
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'status', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topic_suggestions');
    }
};
