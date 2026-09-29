<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 定期実行の設定と実行の記録（D-44）。
 *
 * 設定がない定期実行は、App\Support\ScheduledTasks の既定の時刻で動く（行は、画面で変えたときに作る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_settings', function (Blueprint $table) {
            $table->id();
            // App\Support\ScheduledTasks のキー（例：blogs:sync）
            $table->string('task_key', 50)->unique();
            // daily / weekly
            $table->string('frequency', 10);
            // 毎週の場合の曜日（0=日曜〜6=土曜）
            $table->unsignedTinyInteger('weekday')->nullable();
            // 日本時間の HH:MM
            $table->string('time', 5);
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 50);
            // scheduled（定期実行）/ manual（画面の「今すぐ実行」）
            $table->string('trigger', 20);
            // running / succeeded / failed
            $table->string('status', 20);
            // 定期実行の予定の時刻（開始の遅れを見るため。手動は null）
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at');
            // Queue に登録した処理（同期・Google の取得）は、その処理がすべて終わったとき
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            // 処理した件数（同期は取得した件数、Google は行数、確認は確認した件数など。App\Support\ScheduledTasks の説明）
            $table->unsignedInteger('processed_count')->nullable();
            // 変更・登録など、処理の結果として何かが起きた件数（同期の作成・更新・削除など）
            $table->unsignedInteger('changed_count')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            // 対象のブログの数
            $table->unsignedSmallInteger('blog_count')->nullable();
            // 終わるのを待っている Queue の処理の数
            $table->unsignedSmallInteger('pending_jobs')->default(0);
            // 結果の要約（1行）と、コマンドの出力（ブログごとの結果など）
            $table->text('message')->nullable();
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('peak_memory_mb')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_key', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_task_settings');
    }
};
