<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-39：BlogOS が自動で作った編集案の理由（link_switch：公開された記事へのリンクに切り替える）。
     */
    public function up(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->string('auto_reason', 30)->nullable()->after('finish_notes')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropIndex(['auto_reason']);
            $table->dropColumn('auto_reason');
        });
    }
};
