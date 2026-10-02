<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記事改修で直すべき指摘と、その対応・改修後の確認（D-47 S3）。
 *
 * 記事改修を始めるときに、元にした評価の指摘（○ でない項目）に番号を付けて記録する。改修の出力の対応表で「対応」を、
 * 編集案の品質診断で「改修後の確認」（解消したか）を記録する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revision_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            // 記事改修の AI 実行と、できた編集案
            $table->foreignId('ai_generation_id')->constrained('ai_generations')->cascadeOnDelete();
            $table->foreignId('article_draft_id')->nullable()->constrained('article_drafts')->cascadeOnDelete();
            // 元にした評価
            $table->foreignId('source_evaluation_id')->nullable()->constrained('article_evaluations')->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('item_key', 50);
            // 改修前の判定（partial / bad）
            $table->string('judgment', 20);
            $table->text('location')->nullable();
            $table->text('problem')->nullable();
            $table->text('fix')->nullable();
            // 改修での対応（fixed / partial / not_fixed）と、どこをどう直したか・直さなかった理由
            $table->string('response_status', 20)->nullable();
            $table->text('response_note')->nullable();
            // 改修後の確認（resolved / partial / unresolved）と、その評価
            $table->string('check_status', 20)->nullable();
            $table->text('check_note')->nullable();
            $table->foreignId('check_evaluation_id')->nullable()->constrained('article_evaluations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ai_generation_id', 'number']);
            $table->index('article_draft_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_findings');
    }
};
