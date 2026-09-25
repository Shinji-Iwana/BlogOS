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
     * authors・categories・tags・media と、それぞれの履歴（BLOGOS_DATABASE.md 6-3〜6-6）。
     *
     * 旧い categories・category_histories（WordPress IDを category_id に入れていた構造）は、
     * 設計の構造と異なり、同期でいつでも取得し直せるため、削除して作り直す（BLOGOS_CURRENT_STATUS.md 3-6）。
     */
    public function up(): void
    {
        Schema::dropIfExists('category_histories');
        Schema::dropIfExists('categories');

        Schema::create('authors', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table);
            $table->string('name', 255)->nullable();
            $table->string('slug', 255)->nullable();
            $table->text('url')->nullable();
            $table->text('description')->nullable();
            $table->text('link')->nullable();
            $table->json('avatar_urls')->nullable();
            $table->json('roles')->nullable();
        });

        Schema::create('categories', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table);
            $table->string('name', 255)->nullable();
            $table->string('slug', 255)->nullable()->index();
            $table->text('description')->nullable();
            $table->text('link')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_parent_id')->default(0);
        });

        Schema::create('tags', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table);
            $table->string('name', 255)->nullable();
            $table->string('slug', 255)->nullable()->index();
            $table->text('description')->nullable();
            $table->text('link')->nullable();
        });

        Schema::create('media', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table, hasModified: true);
            $table->text('title_raw')->nullable();
            $table->text('title_rendered')->nullable();
            $table->longText('caption_raw')->nullable();
            $table->longText('caption_rendered')->nullable();
            $table->longText('description_raw')->nullable();
            $table->longText('description_rendered')->nullable();
            $table->text('alt_text')->nullable();
            $table->text('source_url')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('media_type', 50)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('filesize')->nullable();
            $table->json('sizes')->nullable();
            $table->string('slug', 255)->nullable();
            $table->string('status', 50)->nullable();
            $table->text('link')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_author_id')->default(0);
            // 添付先（受け取ったとおり）。記事を解決できた場合の post_id / page_id は次のMigrationで追加する
            $table->unsignedBigInteger('wordpress_post_id')->default(0);
        });

        Schema::create('author_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'author_id', 'authors'));
        Schema::create('category_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'category_id', 'categories'));
        Schema::create('tag_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'tag_id', 'tags'));
        Schema::create('media_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'media_id', 'media'));
    }

    /**
     * Reverse the migrations.
     *
     * 旧い categories・category_histories は復元しない（同期で取得し直せるため）。
     */
    public function down(): void
    {
        Schema::dropIfExists('media_histories');
        Schema::dropIfExists('tag_histories');
        Schema::dropIfExists('category_histories');
        Schema::dropIfExists('author_histories');
        Schema::dropIfExists('media');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('authors');
    }
};
