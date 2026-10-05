<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 音声の操作のモデルの料金を、料金表（ai_prices）に入れ、毎日の公式のページとの照合の対象にする（D-68-02）。
 *
 * ・audio_input・audio_cached_input・audio_output：音声のトークンの料金（100万トークンあたり。リアルタイム会話・返事の声）
 * ・聞き取りのモデルは、1分あたりの料金を per_call に入れる
 * 今の設定の値（config/blogos.php の voice）を、初めの値として入れる（すでにある行は変えない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_prices', function (Blueprint $table) {
            $table->decimal('audio_input', 12, 6)->nullable()->after('image_cached_input');
            $table->decimal('audio_cached_input', 12, 6)->nullable()->after('audio_input');
            $table->decimal('audio_output', 12, 6)->nullable()->after('audio_cached_input');
        });

        $now = now();
        $rows = [];
        foreach ((array) config('blogos.voice.prices.transcribe_per_minute') as $model => $perMinute) {
            $rows[$model] = ['per_call' => $perMinute];
        }
        foreach ((array) config('blogos.voice.prices.tts') as $model => $price) {
            $rows[$model] = ['input' => $price['text_input'], 'audio_output' => $price['audio_output']];
        }
        foreach ((array) config('blogos.voice.realtime.prices') as $model => $price) {
            $rows[$model] = [
                'input' => $price['text_input'], 'cached_input' => $price['text_cached_input'], 'output' => $price['text_output'],
                'audio_input' => $price['audio_input'], 'audio_cached_input' => $price['audio_cached_input'], 'audio_output' => $price['audio_output'],
            ];
        }
        foreach ($rows as $model => $values) {
            if (! DB::table('ai_prices')->where('price_key', $model)->exists()) {
                DB::table('ai_prices')->insert(['price_key' => $model, 'created_at' => $now, 'updated_at' => $now] + $values);
            }
        }
    }

    public function down(): void
    {
        $models = array_merge(
            array_keys((array) config('blogos.voice.prices.transcribe_per_minute')),
            array_keys((array) config('blogos.voice.prices.tts')),
            array_keys((array) config('blogos.voice.realtime.prices')),
        );
        DB::table('ai_prices')->whereIn('price_key', $models)->delete();
        Schema::table('ai_prices', fn (Blueprint $table) => $table->dropColumn(['audio_input', 'audio_cached_input', 'audio_output']));
    }
};
