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
     * カスタム投稿タイプ・カスタムタクソノミー（BLOGOS_DATABASE.md 6-8、D-10-04）。
     * 同期と閲覧だけの対象とし、編集案・反映・評価の対象外とする。
     */
    public function up(): void
    {
        Schema::create('custom_terms', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table);

            // タクソノミーのslug（WordPressの項目IDは全タクソノミーで共通の番号のため、一意キーは blog_id + wordpress_id）
            $table->string('taxonomy', 50)->index();

            $table->string('name', 255)->nullable();
            $table->string('slug', 255)->nullable()->index();
            $table->text('description')->nullable();
            $table->text('link')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('custom_terms')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_parent_id')->default(0);
        });

        Schema::create('custom_contents', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table, true, true);

            $table->text('title_raw')->nullable();
            $table->text('title_rendered')->nullable();
            $table->longText('content_raw')->nullable();
            $table->longText('content_rendered')->nullable();
            $table->text('excerpt_raw')->nullable();
            $table->text('excerpt_rendered')->nullable();

            $table->string('slug', 255)->nullable()->index();
            $table->string('status', 50)->nullable()->index();

            // 投稿タイプのslug（WordPressの投稿IDは全投稿タイプで共通の番号のため、一意キーは blog_id + wordpress_id）
            $table->string('type', 50)->index();
            $table->string('template', 255)->nullable();

            $table->text('link')->nullable();
            $table->string('normalized_path', 500)->nullable()->index();

            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_author_id')->default(0);
            $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_featured_media_id')->default(0);

            $table->foreignId('parent_id')->nullable()->constrained('custom_contents')->nullOnDelete();
            $table->unsignedBigInteger('wordpress_parent_id')->default(0);
            $table->integer('menu_order')->default(0);
        });

        Schema::create('custom_content_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_content_id')->constrained('custom_contents')->cascadeOnDelete();
            $table->foreignId('custom_term_id')->constrained('custom_terms')->cascadeOnDelete();
            $table->unique(['custom_content_id', 'custom_term_id']);
        });

        Schema::create('custom_term_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'custom_term_id', 'custom_terms'));
        Schema::create('custom_content_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'custom_content_id', 'custom_contents'));
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_content_histories');
        Schema::dropIfExists('custom_term_histories');
        Schema::dropIfExists('custom_content_terms');
        Schema::dropIfExists('custom_contents');
        Schema::dropIfExists('custom_terms');
    }
};
