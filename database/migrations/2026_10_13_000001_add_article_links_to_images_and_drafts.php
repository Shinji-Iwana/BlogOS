<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-34：記事改修・新規記事作成への組み込み。AIが依頼した画像を編集案に結び付け、BlogOS の仕上げの結果を編集案に残す。
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // 画像を依頼した編集案（AIの改修案・新規記事の「画像の依頼」から作った画像）
            $table->foreignId('article_draft_id')->nullable()->after('media_id')->constrained('article_drafts')->nullOnDelete();
        });

        Schema::table('article_drafts', function (Blueprint $table) {
            // BlogOS の仕上げ（目印の置き換え・広告の挿入など）で人に伝えること（JSON の文字列の配列）
            $table->json('finish_notes')->nullable()->after('edit_ratio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropColumn('finish_notes');
        });

        Schema::table('images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_draft_id');
        });
    }
};
