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
     * 記事の管理情報・キーワード・記事同士の関係（BLOGOS_DATABASE.md 9-2〜9-4）と、
     * 本文から抽出する内部リンク・本文中のメディア（7章）。
     */
    public function up(): void
    {
        Schema::create('article_managements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);

            $table->string('article_type', 50)->nullable();
            $table->string('article_subtype', 50)->nullable();
            $table->text('main_search_intent')->nullable();
            $table->json('sub_search_intents')->nullable();

            // 作業状態（App\Enums\WorkStatus。D-15-10）
            $table->string('work_status', 20)->default('not_started');

            $table->text('memo')->nullable();
            $table->timestamps();

            // 記事1件につき1行
            $table->unique('post_id');
            $table->unique('page_id');
            $table->index(['blog_id', 'work_status']);
        });
        BlogosSchema::addArticleCheck('article_managements', exactlyOne: true);

        Schema::create('article_management_histories', function (Blueprint $table) {
            BlogosSchema::history($table, 'article_management_id', 'article_managements');
        });

        Schema::create('article_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            $table->string('keyword', 191);
            $table->string('keyword_type', 10); // main / sub
            $table->timestamps();

            $table->unique(['post_id', 'keyword']);
            $table->unique(['page_id', 'keyword']);
            $table->index(['blog_id', 'keyword']);
        });
        BlogosSchema::addArticleCheck('article_keywords', exactlyOne: true);

        Schema::create('article_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);
            BlogosSchema::articleReference($table, 'related_');

            // 関係の種類（App\Enums\RelationType。D-15-10）
            $table->string('relation_type', 30);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        BlogosSchema::addArticleCheck('article_relations', exactlyOne: true);
        BlogosSchema::addArticleCheck('article_relations', exactlyOne: true, prefix: 'related_');

        Schema::create('internal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // リンク元の記事
            BlogosSchema::articleReference($table);

            // リンク先のURL（本文のとおり）と、解決できた場合のリンク先の記事
            $table->text('target_url');
            BlogosSchema::articleReference($table, 'target_');

            $table->text('anchor_text')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'target_post_id']);
            $table->index(['blog_id', 'target_page_id']);
        });
        BlogosSchema::addArticleCheck('internal_links', exactlyOne: true);
        BlogosSchema::addArticleCheck('internal_links', exactlyOne: false, prefix: 'target_');

        Schema::create('article_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            BlogosSchema::articleReference($table);

            // 本文中の画像等のURLと、対応するメディア（本文から判別できた場合）
            $table->text('source_url');
            $table->unsignedBigInteger('wordpress_media_id')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamps();
        });
        BlogosSchema::addArticleCheck('article_media', exactlyOne: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_media');
        Schema::dropIfExists('internal_links');
        Schema::dropIfExists('article_relations');
        Schema::dropIfExists('article_keywords');
        Schema::dropIfExists('article_management_histories');
        Schema::dropIfExists('article_managements');
    }
};
