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
     * D-37：Search Console の URL 検査 API で調べた、記事ごとのインデックスの登録状態（記事ごとに最新の1行）。
     */
    public function up(): void
    {
        Schema::create('google_index_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            $table->text('url');

            // 分類（App\Enums\GoogleIndexCategory）と、Search Console の状態（英語の原文）
            $table->string('category', 20)->nullable();
            $table->string('coverage_state', 255)->nullable();
            $table->string('verdict', 30)->nullable();
            $table->string('indexing_state', 50)->nullable();
            $table->string('robots_txt_state', 50)->nullable();
            $table->string('page_fetch_state', 50)->nullable();
            $table->timestamp('last_crawl_at')->nullable();
            $table->text('google_canonical')->nullable();
            $table->text('user_canonical')->nullable();
            // 調べられなかった場合の理由
            $table->text('error')->nullable();

            $table->timestamp('inspected_at')->nullable();
            // 分類が変わった日時と、前の分類（改修の効果を追うため）
            $table->string('previous_category', 20)->nullable();
            $table->timestamp('category_changed_at')->nullable();
            $table->timestamps();

            $table->unique('post_id');
            $table->unique('page_id');
            $table->index(['blog_id', 'category']);
        });
        BlogosSchema::addArticleCheck('google_index_statuses', exactlyOne: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_index_statuses');
    }
};
