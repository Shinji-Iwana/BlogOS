<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * メタディスクリプション（D-23-01〜D-23-03）。
     *
     * WordPressの標準のAPIにはないため、SEOプラグイン（AIOSEO）が投稿・固定ページのAPIに加える項目から取得する。
     * * meta_description_raw：記事に設定した説明（AIOSEO の aioseo_meta_data.description。未設定は NULL）
     * * meta_description_rendered：実際にページに出力される説明（aioseo_head_json.description。未設定の場合は、AIOSEOが本文から自動で作る）
     */
    public function up(): void
    {
        foreach (['posts', 'pages'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('meta_description_raw')->nullable()->after('excerpt_rendered');
                $table->text('meta_description_rendered')->nullable()->after('meta_description_raw');
            });
        }

        Schema::table('article_drafts', function (Blueprint $table) {
            // 反映時に設定するメタディスクリプション（空にすると、AIOSEOの自動の説明に戻る）
            $table->text('meta_description')->nullable()->after('excerpt_raw');
        });
    }

    public function down(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropColumn('meta_description');
        });

        foreach (['posts', 'pages'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['meta_description_raw', 'meta_description_rendered']);
            });
        }
    }
};
