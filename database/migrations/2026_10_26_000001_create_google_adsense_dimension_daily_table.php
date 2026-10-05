<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AdSense の、広告ユニット・国・入札方法・トラフィックソースごと × 日の数値（画面「AdSense」のカード。D-67）。
 * dimension：ad_unit / country / bid_type / traffic_source、value：その名前（例：rectangle-top・日本・CPM・Google）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_adsense_dimension_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 20);
            $table->string('value', 255);
            $table->string('currency_code', 3)->nullable();
            $table->decimal('estimated_earnings', 14, 4)->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['blog_id', 'date', 'dimension', 'value'], 'adsense_dimension_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_adsense_dimension_daily');
    }
};
