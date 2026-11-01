<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-90：古い画像（/wp-content/img/…）の移し替え。どの古いファイルから取り込んだ画像かを残す（同じファイルは1回だけ取り込む）。
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // 取り込み元の古いファイルのパス（/wp-content/img/… 。URL の符号化を戻した形）
            $table->string('legacy_path', 255)->nullable()->after('article_draft_id');
            $table->index(['blog_id', 'legacy_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(['blog_id', 'legacy_path']);
            $table->dropColumn('legacy_path');
        });
    }
};
