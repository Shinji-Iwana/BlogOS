<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 人の対応が必要な問題（BLOGOS_DATABASE.md 10-3）。
     * ログイン時・ダッシュボードの通知は、未解決のものを表示する（D-01-05）。
     */
    public function up(): void
    {
        Schema::create('sync_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();

            // 反映記録・編集案（段階4で表を作るときに外部キーを付ける）
            $table->unsignedBigInteger('wordpress_push_operation_id')->nullable()->index();
            $table->unsignedBigInteger('article_draft_id')->nullable()->index();

            // 問題の種類（App\Enums\SyncIssueType）
            $table->string('issue_type', 50);

            // 対象
            $table->string('resource_type', 50)->nullable();
            $table->string('resource_key', 255)->nullable(); // WordPress ID または slug
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();

            $table->text('message');

            // エラー時のHTTPステータスと応答本文（全文。D-10-03）
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->longText('error_body')->nullable();

            // 最初に検出した日時と、最後に検出した日時（同じ問題は1行にまとめる。D-15-08）
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();

            $table->timestamps();

            $table->index(['blog_id', 'resolved_at']);
            $table->index(['blog_id', 'issue_type', 'resource_type', 'resource_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_issues');
    }
};
