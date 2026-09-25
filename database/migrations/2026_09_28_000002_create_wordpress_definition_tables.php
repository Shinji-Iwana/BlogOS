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
     * WordPressの定義情報（statuses・types・taxonomies）と、それぞれの履歴（BLOGOS_DATABASE.md 6-7、D-05-05）。
     * 数値IDを持たないため、blog_id + slug で識別する（D-02-04）。
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table, numericId: false);
            $table->string('slug', 100);
            $table->string('name', 255)->nullable();
            $table->boolean('public')->nullable();
            $table->boolean('queryable')->nullable();
            $table->boolean('show_in_list')->nullable();
            $table->unique(['blog_id', 'slug']);
        });

        Schema::create('types', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table, numericId: false);
            $table->string('slug', 100);
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('hierarchical')->nullable();
            $table->string('rest_base', 100)->nullable();
            $table->string('rest_namespace', 100)->nullable();
            $table->json('taxonomies')->nullable();
            $table->unique(['blog_id', 'slug']);
        });

        Schema::create('taxonomies', function (Blueprint $table) {
            BlogosSchema::wordpressOrigin($table, numericId: false);
            $table->string('slug', 100);
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('hierarchical')->nullable();
            $table->string('rest_base', 100)->nullable();
            $table->string('rest_namespace', 100)->nullable();
            $table->json('types')->nullable();
            $table->unique(['blog_id', 'slug']);
        });

        Schema::create('status_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'status_id', 'statuses'));
        Schema::create('type_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'type_id', 'types'));
        Schema::create('taxonomy_histories', fn (Blueprint $t) => BlogosSchema::history($t, 'taxonomy_id', 'taxonomies'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxonomy_histories');
        Schema::dropIfExists('type_histories');
        Schema::dropIfExists('status_histories');
        Schema::dropIfExists('taxonomies');
        Schema::dropIfExists('types');
        Schema::dropIfExists('statuses');
    }
};
