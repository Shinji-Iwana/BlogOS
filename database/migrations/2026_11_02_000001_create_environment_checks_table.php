<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * サーバー環境の点検（D-88）。対象（PHP・BlogOS の部品・Laravel・DB）ごとに、最新の点検の結果を1行で持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_checks', function (Blueprint $table) {
            $table->id();
            // 対象（php_web・php_cli・packages・laravel・database）
            $table->string('target', 32)->unique();
            $table->string('label');
            $table->string('current_version', 64)->nullable();
            // 同じ系列の最新の版
            $table->string('latest_version', 64)->nullable();
            // セキュリティの修正の終わり
            $table->date('support_until')->nullable();
            // 状態（security：すぐ対応、support：計画して対応、ok：問題なし、info：参考、unknown：読み取れない）
            $table->string('level', 16);
            // すること・状態の説明
            $table->text('message')->nullable();
            // 見つかった脆弱性・入っていないセキュリティの修正版など
            $table->json('details')->nullable();
            // XServer の MySQL は初期値のない NOT NULL の timestamp を作れないため、null を許す（ほかの記録のテーブルと同じ）
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_checks');
    }
};
