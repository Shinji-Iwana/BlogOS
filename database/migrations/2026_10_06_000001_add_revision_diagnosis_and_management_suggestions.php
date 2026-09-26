<?php

use App\Support\Database\BlogosSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-27：改修の後の編集案の品質診断（改修前後の点数）、自動の改修範囲、AIによる記事の管理情報の案。
     */
    public function up(): void
    {
        Schema::table('ai_batch_items', function (Blueprint $table) {
            // 記事改修：使った改修範囲（点数で自動判別した場合は、判別した結果）と、改修前後の点数
            $table->string('revision_scope', 20)->nullable()->after('reason');
            $table->decimal('score_before', 4, 1)->nullable()->after('revision_scope');
            $table->decimal('score_after', 4, 1)->nullable()->after('score_before');
            // 改修の後に、できた編集案を品質診断したAI実行記録
            $table->foreignId('diagnosis_generation_id')->nullable()->after('ai_generation_id')->constrained('ai_generations')->nullOnDelete();
        });

        Schema::table('blog_ai_settings', function (Blueprint $table) {
            // 自動の再評価の後の改修範囲（auto：点数で自動判別 / minor / restructure / full）
            $table->string('auto_revision_scope', 20)->default('auto')->after('auto_revision_reasoning_effort');
        });

        // AIが作った記事の管理情報（記事種類・キーワード・検索意図）の案。人が確認して登録する
        Schema::create('article_management_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();

            $table->string('article_type', 50)->nullable();
            $table->string('article_subtype', 50)->nullable();
            $table->string('main_keyword', 191)->nullable();
            $table->json('sub_keywords')->nullable();
            $table->text('main_search_intent')->nullable();
            $table->json('sub_search_intents')->nullable();
            // AIが示した理由（人が判断するため）
            $table->text('reason')->nullable();

            // 状態（App\Enums\SuggestionStatus）
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'status']);
        });
        BlogosSchema::addArticleCheck('article_management_suggestions', exactlyOne: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_management_suggestions');

        if (Schema::hasColumn('blog_ai_settings', 'auto_revision_scope')) {
            Schema::table('blog_ai_settings', fn (Blueprint $table) => $table->dropColumn('auto_revision_scope'));
        }

        if (Schema::hasColumn('ai_batch_items', 'diagnosis_generation_id')) {
            Schema::table('ai_batch_items', function (Blueprint $table) {
                $table->dropForeign(['diagnosis_generation_id']);
                $table->dropColumn(['revision_scope', 'score_before', 'score_after', 'diagnosis_generation_id']);
            });
        }
    }
};
