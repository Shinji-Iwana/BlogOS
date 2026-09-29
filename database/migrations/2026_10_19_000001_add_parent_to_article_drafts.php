<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-41：固定ページの親のページ（WordPress の ID）。子ロードマップを親ロードマップの子のページにして、URL を /親/子.html にするため。
     */
    public function up(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('wordpress_parent_id')->nullable()->after('wordpress_featured_media_id');
        });

        Schema::table('category_launches', function (Blueprint $table) {
            // 親ロードマップの編集案（最初に WordPress の下書きとして作り、⑧で中身を作って公開する）
            $table->foreignId('parent_roadmap_draft_id')->nullable()->after('parent_category_id')->constrained('article_drafts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_launches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_roadmap_draft_id');
        });

        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropColumn('wordpress_parent_id');
        });
    }
};
