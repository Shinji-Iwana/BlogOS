<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-31-04：OpenAI の残高（前払いのクレジット）を BlogOS で見込む。人が OpenAI の画面で見た残高と、課金（チャージ）した額を登録する。
     * 残高の見込み ＝ 最後に登録した残高 ＋ その後の課金 − その後のAPI実行の費用の目安。
     */
    public function up(): void
    {
        Schema::create('ai_credit_entries', function (Blueprint $table) {
            $table->id();
            // 種類（App\Enums\AiCreditEntryType：balance：OpenAI の画面で見た残高 / purchase：課金した額）
            $table->string('type', 20);
            // 米ドル（課金は、残高に加わった額。税は含めない）
            $table->decimal('amount', 10, 2);
            // 残高を見た日時・課金した日時
            $table->timestamp('occurred_at');
            // 残高の登録：その時点での BlogOS の見込み（実際との差を示すため）
            $table->decimal('estimated_balance', 10, 4)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_entries');
    }
};
