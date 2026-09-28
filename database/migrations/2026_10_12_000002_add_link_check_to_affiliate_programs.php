<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-33-09：プログラムのリンクの定期確認（提携終了の疑いの検知）の結果。
     */
    public function up(): void
    {
        Schema::table('affiliate_programs', function (Blueprint $table) {
            // 確認に使ったリンク（記事でいちばん多く使っているもの）、結果（App\Enums\AffiliateLinkCheckResult）、説明、日時
            $table->text('check_url')->nullable()->after('memo');
            $table->string('check_result', 20)->nullable()->after('check_url');
            $table->text('check_detail')->nullable()->after('check_result');
            $table->timestamp('checked_at')->nullable()->after('check_detail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_programs', function (Blueprint $table) {
            $table->dropColumn(['check_url', 'check_result', 'check_detail', 'checked_at']);
        });
    }
};
