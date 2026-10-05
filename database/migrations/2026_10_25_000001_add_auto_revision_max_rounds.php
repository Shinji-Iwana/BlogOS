<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記事の再評価（自動の再評価）の後の改修を、基準を満たすまで繰り返す上限の回数（D-65）。ブログごと。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_ai_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_revision_max_rounds')->default(3)->after('auto_revision_scope');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('blog_ai_settings', 'auto_revision_max_rounds')) {
            Schema::table('blog_ai_settings', fn (Blueprint $table) => $table->dropColumn('auto_revision_max_rounds'));
        }
    }
};
