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
     * D-30：収益用の教材（書籍・Udemy・スクール）の登録、AIによる情報の調査・候補探し、記事との関係と見直し。
     */
    public function up(): void
    {
        // 教材。紹介に使うアフィリエイトのリンクと、記事に合う教材を選ぶための情報
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 種類（App\Enums\MaterialKind：book / udemy / school）と状態（App\Enums\MaterialStatus：active / inactive）
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('name', 255);

            // アフィリエイトのリンク。書籍は Amazon と楽天（もしも経由）、Udemy・スクールは affiliate_url
            $table->text('amazon_url')->nullable();
            $table->text('rakuten_url')->nullable();
            $table->text('affiliate_url')->nullable();
            // 同じ教材の別のリンク（記事で使われている古いリンクなど。記事との照合に使う）
            $table->json('extra_urls')->nullable();
            // 商品ページ（アフィリエイトではないURL。AIの調査と、定期チェックでの確認に使う）
            $table->text('product_url')->nullable();

            // 書籍の識別子
            $table->string('isbn', 13)->nullable();
            $table->string('asin', 20)->nullable();

            // 著者・講師・運営会社、出版社・提供元、版、出版日（Udemyは最終更新日）
            $table->string('creator', 255)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->string('edition', 50)->nullable();
            $table->date('published_on')->nullable();

            // 記事に合う教材を選ぶための情報（AIが調査し、人が確認する）
            $table->json('topics')->nullable();
            $table->json('target_versions')->nullable();
            $table->json('levels')->nullable();
            $table->json('scenes')->nullable();
            $table->text('summary')->nullable();
            $table->text('target_readers')->nullable();
            $table->text('not_for')->nullable();
            $table->json('merits')->nullable();
            $table->json('cautions')->nullable();
            // スクール：費用の目安・学習期間と、確認した日
            $table->string('cost_note', 255)->nullable();
            $table->string('duration_note', 255)->nullable();
            $table->date('cost_checked_on')->nullable();
            // 調査の根拠のURL
            $table->json('sources')->nullable();

            // 前の版（第2版 → 第3版など）
            $table->foreignId('previous_material_id')->nullable()->constrained('materials')->nullOnDelete();

            $table->text('memo')->nullable();
            // 教材の情報を最後に更新した日時（この教材を使う記事の見直しの判定に使う）
            $table->timestamp('info_updated_at')->nullable();
            // 最後にAIで調査した日時（定期チェックの判定に使う）
            $table->timestamp('researched_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['blog_id', 'kind', 'status']);
            $table->index(['blog_id', 'isbn']);
        });

        // 教材の分野（ブログのカテゴリ）。記事のカテゴリとの一致で、候補を絞る
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->unique(['material_id', 'category_id']);
        });

        // AIが作った教材の案（登録済みの教材の情報／新しい教材の候補）。人が確認して登録する
        Schema::create('material_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            // 種類（App\Enums\MaterialSuggestionType：research / candidate）
            $table->string('type', 20);
            // 調査の対象の教材。新しい教材の候補では、登録したときに登録した教材
            $table->foreignId('material_id')->nullable()->constrained('materials')->cascadeOnDelete();
            // 新しい版の候補のときの、前の版
            $table->foreignId('related_material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();

            $table->string('kind', 20);
            $table->string('name', 255);
            // 案の値（materials の列と同じ名前）
            $table->json('data');
            $table->text('reason')->nullable();

            // 状態（App\Enums\SuggestionStatus）
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'status']);
        });

        // 記事で使っている教材
        Schema::create('article_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();

            // 記録の元（App\Enums\ArticleMaterialSource：detected / human）
            $table->string('source', 20);
            // 最後に見直した日時（教材の情報・記事がこれより後に更新されたら、見直しが必要）
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'material_id']);
            $table->unique(['page_id', 'material_id']);
        });
        BlogosSchema::addArticleCheck('article_materials', exactlyOne: true);

        // AIによる、記事の教材の見直しの結果。人が確認する
        Schema::create('article_material_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();

            // 今の教材ごとの判定と、追加の候補（App\Services\Materials\MaterialReviewResult の形）
            $table->json('result');
            $table->text('summary')->nullable();

            // 状態（App\Enums\SuggestionStatus。Accepted は「確認した」）
            $table->string('status', 20);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'status']);
        });
        BlogosSchema::addArticleCheck('article_material_reviews', exactlyOne: true);

        Schema::table('ai_generations', function (Blueprint $table) {
            // 教材の調査の対象
            $table->foreignId('material_id')->nullable()->after('article_draft_id')->constrained('materials')->nullOnDelete();
            // Web検索を使うか（API実行）と、使った回数
            $table->boolean('use_web_search')->default(false)->after('reasoning_effort');
            $table->unsignedInteger('web_search_calls')->nullable()->after('reasoning_tokens');
        });

        // 記事の対象のバージョン（例：PHP 8.3、Laravel 11）。教材のバージョンとの比較に使う
        Schema::table('article_managements', function (Blueprint $table) {
            $table->text('target_versions')->nullable()->after('sub_search_intents');
        });
        Schema::table('article_management_suggestions', function (Blueprint $table) {
            $table->text('target_versions')->nullable()->after('sub_search_intents');
        });

        Schema::table('blog_ai_settings', function (Blueprint $table) {
            // 教材の定期チェック（API実行）
            $table->boolean('material_check_enabled')->default(false)->after('auto_revision_scope');
        });
    }

    public function down(): void
    {
        foreach (['blog_ai_settings' => 'material_check_enabled', 'article_management_suggestions' => 'target_versions', 'article_managements' => 'target_versions'] as $name => $column) {
            if (Schema::hasColumn($name, $column)) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasColumn('ai_generations', 'material_id')) {
            Schema::table('ai_generations', function (Blueprint $table) {
                $table->dropForeign(['material_id']);
                $table->dropColumn(['material_id', 'use_web_search', 'web_search_calls']);
            });
        }

        Schema::dropIfExists('article_material_reviews');
        Schema::dropIfExists('article_materials');
        Schema::dropIfExists('material_suggestions');
        Schema::dropIfExists('material_categories');
        Schema::dropIfExists('materials');
    }
};
