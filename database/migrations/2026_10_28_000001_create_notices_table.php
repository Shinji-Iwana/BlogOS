<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * お知らせ（D-74）。以前はトップページを開くたびに今の状態から作って出していたもの（要対応・注意）を、1件ずつ記録する。
 *
 * 同じ種類の問題の内容が変わったときは、新しいお知らせとして記録し、変わる前のお知らせには変動した日時を残す。
 * 問題がなくなったときは、最新のお知らせに解消した日時を残す。どちらも消さない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            // ブログごとのお知らせ（リンク切れなど）はブログ、全体のお知らせ（料金表・定期実行など）は null
            $table->foreignId('blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            // 種類（App\Services\Notices\NoticeService の KINDS）
            $table->string('kind', 50);
            // 色：error（要対応）／warn（注意）
            $table->string('level', 10);
            $table->text('message');
            // 確認する画面へのリンク
            $table->text('url')->nullable();
            $table->string('link_label', 100)->nullable();
            // 内容の印（件数など。変わったら、新しいお知らせにする）
            $table->string('signature', 255);
            // お知らせ日。XServer の MySQL は初期値のない NOT NULL の timestamp を作れないため、null を許す（ほかの記録のテーブルと同じ）
            $table->timestamp('occurred_at')->nullable();
            // 内容が変わり、新しいお知らせを記録した日時（解消ではない）
            $table->timestamp('changed_at')->nullable();
            // 問題がなくなった日時
            $table->timestamp('resolved_at')->nullable();
            // 確認済みにした日時と人
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'blog_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
