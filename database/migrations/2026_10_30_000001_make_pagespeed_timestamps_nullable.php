<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 空を許さない日時の列を、空を許す形にする（D-78-07）。
 *
 * MySQL・MariaDB で explicit_defaults_for_timestamp が OFF の環境（本番の XServer）では、表の中で最初の、空を許さない timestamp の列に
 * 「行を書き換えるたびに、今の時刻を自動で入れる」設定が付く。pagespeed_runs の started_at は、測り終えて結果を書き込むときに、
 * DB の接続の時刻（日本時間）で上書きされ、UTC として読むため、画面で9時間後になった（手元の MySQL 8 は ON のため起きなかった）。
 * ほかの記録と同じく nullable にし、その設定を外す（列を定義し直すと外れる）。同じ形の ai_credit_entries.occurred_at も直す。
 *
 * 上書きされた記録の開始の日時は戻せないため、終了の日時にそろえる（開始が終了より後のもの）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagespeed_runs', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->change();
        });
        Schema::table('pagespeed_responses', function (Blueprint $table) {
            $table->timestamp('fetched_at')->nullable()->change();
        });
        Schema::table('ai_credit_entries', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->change();
        });

        DB::table('pagespeed_runs')->whereNotNull('finished_at')->whereColumn('started_at', '>', 'finished_at')->update(['started_at' => DB::raw('finished_at')]);
    }

    public function down(): void
    {
        // 元の形（空を許さない）には戻さない（戻すと、同じずれが起きるため）
    }
};
