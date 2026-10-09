<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PageSpeed Insights の測定（D-78）。
 *
 * - pagespeed_runs：測定の履歴（1つの URL を、携帯かデスクトップで1回測ったごとに1行）。点数・主な値・合格しなかった項目を残す（1年で削除）
 * - pagespeed_responses：応答の全部（URL×携帯・デスクトップごとに最新の1回だけ。圧縮して残し、測り直したら置き換える）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagespeed_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            // 記事（どちらもなければ、ブログのトップページ）
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            // mobile / desktop
            $table->string('strategy', 10);
            // scheduled（定期実行）/ manual（即時実行・画面の「今すぐ測定」）
            $table->string('trigger', 20);
            // running / succeeded / failed
            $table->string('status', 20);

            // 4つの区分の点数（0〜100）
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('accessibility_score')->nullable();
            $table->unsignedTinyInteger('best_practices_score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();

            // 試しに開いた値（Lighthouse。ミリ秒。CLS は単位なし）
            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            $table->unsignedInteger('si_ms')->nullable();
            $table->decimal('cls', 6, 3)->nullable();

            // 実際の利用者の値（Chrome の利用者の記録。75パーセンタイル）：この URL と、サイト全体（オリジン）。アクセスが少ないとない
            $table->unsignedInteger('field_lcp_ms')->nullable();
            $table->unsignedInteger('field_inp_ms')->nullable();
            $table->decimal('field_cls', 6, 3)->nullable();
            // FAST / AVERAGE / SLOW
            $table->string('field_category', 20)->nullable();
            $table->unsignedInteger('origin_lcp_ms')->nullable();
            $table->unsignedInteger('origin_inp_ms')->nullable();
            $table->decimal('origin_cls', 6, 3)->nullable();
            $table->string('origin_category', 20)->nullable();

            // 合格しなかった診断の項目（[{id, categories, title, score, display_value}]）
            $table->json('failed_audits')->nullable();
            $table->string('lighthouse_version', 20)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'started_at']);
            $table->index(['post_id', 'strategy']);
            $table->index(['page_id', 'strategy']);
        });

        Schema::create('pagespeed_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            // URL は長いため、一意にするのは URL の SHA-256
            $table->char('url_hash', 64);
            $table->string('url', 2048);
            $table->string('strategy', 10);
            $table->foreignId('pagespeed_run_id')->nullable()->constrained('pagespeed_runs')->nullOnDelete();
            // 応答の JSON を gzip で圧縮し、base64 にしたもの
            $table->longText('response');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['url_hash', 'strategy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagespeed_responses');
        Schema::dropIfExists('pagespeed_runs');
    }
};
