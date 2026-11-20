<?php

use App\Support\Database\BlogosSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA4 の外部リンクのクリック（拡張計測の「離脱クリック」。イベント click）：ページ×リンク×日（D-91-07）。
 * アフィリエイトのリンクが、どの記事で何回押されたかを数える。どの教材のリンクかも記録する（D-91-08）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_analytics_outbound_click_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->date('date');
            $table->text('page_path');
            $table->char('page_path_hash', 40);
            $table->string('normalized_path', 500)->nullable();
            BlogosSchema::articleReference($table);

            // 押されたリンク（GA4 の値のとおり。GA4 はイベントの値を100文字で切るため、長い URL は途中まで）
            $table->text('link_url');
            $table->char('link_url_hash', 40);
            $table->string('link_domain', 255)->nullable();
            // 押されたリンクの class（GA4 のカスタムディメンション link_classes。登録前の日・class のないリンクは空）。
            // BlogOS が置いた教材のリンクには blogos-m{教材ID} が入る（D-91-08）
            $table->string('link_classes', 100)->default('');
            // アフィリエイトのプログラム（AffiliateLink::programKey。アフィリエイトでないリンクは NULL）
            $table->string('program', 100)->nullable();
            // どの教材のリンクか（class、または記事の教材との照合で決める。決められないときは NULL。D-91-08）
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->unsignedInteger('clicks')->default(0);

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['blog_id', 'date', 'page_path_hash', 'link_url_hash', 'link_classes'], 'ga_outbound_click_daily_unique');
            $table->index(['blog_id', 'normalized_path'], 'google_analytics_outbound_click_daily_path_index');
            $table->index(['blog_id', 'material_id', 'date'], 'google_analytics_outbound_click_daily_material_index');
        });
        BlogosSchema::addArticleCheck('google_analytics_outbound_click_daily', exactlyOne: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('google_analytics_outbound_click_daily');
    }
};
