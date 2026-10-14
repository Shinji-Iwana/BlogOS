<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 収益記事の企画（D-83-04）：記事の案に、比べる教材と、収益記事へのリンクを置く集客記事を持たせる
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            // 収益記事の案で比べる教材（教材の番号の一覧）
            $table->json('material_ids')->nullable()->after('sources');
            // 収益記事へのリンクを置く集客記事（投稿の番号の一覧）
            $table->json('link_post_ids')->nullable()->after('material_ids');
        });
    }

    public function down(): void
    {
        Schema::table('topic_suggestions', function (Blueprint $table) {
            $table->dropColumn(['material_ids', 'link_post_ids']);
        });
    }
};
