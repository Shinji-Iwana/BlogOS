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
     * posts・pages・中間テーブルと、それぞれの履歴（BLOGOS_DATABASE.md 6-1・6-2）。
     * 本文は raw と rendered の両方を持つ（D-05-07）。
     */
    public function up(): void
    {
        foreach (['pages', 'posts'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                BlogosSchema::wordpressOrigin($table, hasModified: true);

                $table->text('title_raw')->nullable();
                $table->text('title_rendered')->nullable();
                $table->longText('content_raw')->nullable();
                $table->longText('content_rendered')->nullable();
                $table->text('excerpt_raw')->nullable();
                $table->text('excerpt_rendered')->nullable();

                $table->string('slug', 255)->nullable()->index();
                $table->string('status', 50)->nullable()->index();
                $table->string('type', 50)->nullable();
                $table->string('template', 255)->nullable();
                $table->string('comment_status', 20)->nullable();
                $table->string('ping_status', 20)->nullable();

                $table->text('link')->nullable();
                // link からドメイン・末尾のスラッシュ・クエリを除いたもの（Googleデータとの対応付け。D-08-05）
                $table->string('normalized_path', 500)->nullable()->index();

                $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
                $table->unsignedBigInteger('wordpress_author_id')->default(0);
                $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();
                $table->unsignedBigInteger('wordpress_featured_media_id')->default(0);

                if ($name === 'pages') {
                    $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
                    $table->unsignedBigInteger('wordpress_parent_id')->default(0);
                    $table->integer('menu_order')->default(0);
                } else {
                    $table->string('format', 50)->nullable();
                    $table->boolean('sticky')->default(false);
                }
            });
        }

        Schema::create('post_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unique(['post_id', 'category_id']);
        });

        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });

        // メディアの添付先を、記事（投稿・固定ページ）として解決できた場合に設定する（多くとも一方。D-10-02）
        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('post_id')->nullable()->after('wordpress_post_id')->constrained('posts')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->after('post_id')->constrained('pages')->nullOnDelete();
        });

        Schema::create('page_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'page_id', 'pages'));
        Schema::create('post_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'post_id', 'posts'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_histories');
        Schema::dropIfExists('page_histories');

        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
            $table->dropConstrainedForeignId('post_id');
        });

        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_categories');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('pages');
    }
};
