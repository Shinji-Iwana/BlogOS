<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 収益記事へのリンクを、集客記事に入れた記録（D-93-02）。収益記事×集客記事で1行。
 * 収益記事が公開されたら、同じカテゴリの集客記事（と、企画の案がリンクを置く記事に選んだ記事）の教材の紹介の最後に、
 * 収益記事へのリンクの1文を入れる編集案を作る（作業中の編集案があれば、その最新の編集案に足す）。同じ組を二度処理しないために記録する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_article_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            // 収益記事
            $table->foreignId('revenue_post_id')->constrained('posts')->cascadeOnDelete();
            // リンクを入れる集客記事
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            // リンクを入れた編集案（新しく作ったもの、または作業中だったもの）
            $table->foreignId('article_draft_id')->nullable()->constrained('article_drafts')->nullOnDelete();
            // added：編集案に入れた、linked：もうリンクがある、no_box：教材の紹介の枠がない（次の改修で AI が入れる。枠ができたら入れ直す）
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['revenue_post_id', 'post_id']);
            $table->index(['blog_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_article_links');
    }
};
