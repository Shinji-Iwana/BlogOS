<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 音声のやり取りの記録（D-58）。1回の発言（録音）ごとに1行。
 *
 * 聞き取った文字・返事・使った道具・費用を残し、費用は「AIの費用と残高」の見込みと月の上限に含める。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            // 方式（c：順番に処理。d・e：リアルタイム会話）
            $table->string('mode', 10);
            // 聞き取った文字と返事
            $table->text('transcript')->nullable();
            $table->text('reply')->nullable();
            // 呼んだ道具と結果（[{name, arguments, result}]）
            $table->json('tool_calls')->nullable();
            // 画面を移る命令なら、その URL
            $table->text('navigate_url')->nullable();
            // 録音の長さ（秒）
            $table->decimal('audio_seconds', 6, 2)->default(0);
            // 使ったモデルとトークン数（判断の文章の AI）
            $table->string('transcribe_model', 100)->nullable();
            $table->string('text_model', 100)->nullable();
            $table->string('tts_model', 100)->nullable();
            $table->string('voice', 50)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // 費用の目安（米ドル。聞き取り＋判断＋返事の声）
            $table->decimal('estimated_cost', 12, 6)->default(0);
            // 失敗した場合の理由
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_turns');
    }
};
