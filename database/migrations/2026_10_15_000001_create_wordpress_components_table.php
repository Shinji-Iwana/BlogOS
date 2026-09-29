<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-38：WordPress 本体・プラグイン・テーマのバージョンと、WordPress.org の最新のバージョン（ブログごとの最新の状態）。
     */
    public function up(): void
    {
        Schema::create('wordpress_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 種類（core / plugin / theme）と識別子（プラグインは「フォルダ/ファイル」、テーマはフォルダ名、本体は wordpress）
            $table->string('type', 10);
            $table->string('slug', 255);
            $table->string('name', 255)->nullable();
            // 有効か（プラグイン：active / inactive、テーマ：active / inactive、本体は null）
            $table->string('status', 20)->nullable();
            $table->string('installed_version', 50)->nullable();

            // WordPress.org の情報（available：公開中 / closed：公開停止 / not_found：見つからない（自作など））
            $table->string('wporg_state', 20)->nullable();
            $table->string('latest_version', 50)->nullable();
            $table->string('requires_php', 20)->nullable();
            $table->string('requires_wp', 20)->nullable();
            $table->boolean('update_available')->default(false);
            // 公開停止の日付・理由
            $table->text('wporg_note')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['blog_id', 'type', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_components');
    }
};
