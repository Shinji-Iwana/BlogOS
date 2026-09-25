<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WordPress側の拡張（投稿メタ _blogos_draft_id）が有効かどうかの判定結果（WORDPRESS_API 26章、D-20-03）。
     * ブログ登録時と接続確認時に判定して保存し、新規作成の反映で使う。
     */
    public function up(): void
    {
        Schema::table('blog_credentials', function (Blueprint $table) {
            // true：有効 / false：無効 / NULL：判定できなかった（投稿が1件もない場合など）
            $table->boolean('connector_extension')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('blog_credentials', function (Blueprint $table) {
            $table->dropColumn('connector_extension');
        });
    }
};
