<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 同期の実行記録（BLOGOS_DATABASE.md 10-1・10-2、D-04-01）。
     */
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 実行契機（App\Enums\SyncTrigger）
            $table->string('trigger', 20);

            // 状態（App\Enums\SyncStatus）
            $table->string('status', 20);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // 手動で実行した利用者
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            // 同期全体が止まった場合の理由（リソースごとの失敗は sync_run_resources・sync_issues に記録する）
            $table->text('message')->nullable();

            $table->timestamps();

            $table->index(['blog_id', 'started_at']);
        });

        Schema::create('sync_run_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();

            // 投稿・固定ページ・カテゴリなど（App\Services\Sync の各リソースのキー）
            $table->string('resource_type', 50);

            $table->string('status', 20);

            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);

            $table->text('message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_run_resources');
        Schema::dropIfExists('sync_runs');
    }
};
