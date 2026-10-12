<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BlogOS の DB の使用量の、ブログごとの内訳（D-71-09）。
 *
 * 内訳の計算は重いため（テーブルの全ての行のデータの長さを数える）、その日の定期実行が全て終わった後に計算して保存し、
 * XServer情報の画面は、最新の計算の結果を読むだけにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 計算1回につき1行
        Schema::create('database_usage_measurements', function (Blueprint $table) {
            $table->id();
            // 計算した時点の DB 全体の容量（データと索引）と上限（設定）
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('capacity_bytes')->nullable();
            $table->unsignedSmallInteger('table_count');
            // 計算にかかった時間（ミリ秒）
            $table->unsignedInteger('duration_ms');
            // この計算をした定期実行の記録（コマンドを SSH で直接実行した場合は null）
            $table->foreignId('scheduled_task_run_id')->nullable()->constrained('scheduled_task_runs')->nullOnDelete();
            // XServer の MySQL は初期値のない NOT NULL の timestamp を作れないため、null を許す（ほかの記録のテーブルと同じ）
            $table->timestamp('measured_at')->nullable()->index();
            $table->timestamps();
        });

        // 計算1回につき「テーブル × ブログ（どのブログにも属さない分は blog_id が null）」ごとに1行
        Schema::create('database_usage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_usage_measurement_id')->constrained('database_usage_measurements')->cascadeOnDelete();
            $table->string('table_name', 64);
            // ブログを消したら、その分は「共通」として残す
            $table->foreignId('blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            $table->unsignedInteger('row_count');
            // 容量の見積もり（テーブルの容量を、行のデータの長さの割合で分けた値）
            $table->unsignedBigInteger('estimated_bytes');
            $table->timestamps();
            $table->index(['database_usage_measurement_id', 'table_name'], 'database_usage_items_measurement_table_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_usage_items');
        Schema::dropIfExists('database_usage_measurements');
    }
};
