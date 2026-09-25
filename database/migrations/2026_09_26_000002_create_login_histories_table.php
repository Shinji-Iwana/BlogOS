<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * login_historiesテーブルを作成する。
     *
     * BlogOSへのログインの成功・失敗とログアウトを記録し、
     * 不正なログインの有無を確認できるようにする（BLOGOS_DECISIONS.md D-17-03）。
     * パスワードは、成功・失敗にかかわらず記録しない。
     */
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();

            // ログインした利用者
            // 失敗した場合など、該当する利用者がいない場合はNULL
            // 利用者を削除しても記録は残すため、SET NULLとする
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // 入力されたメールアドレス（失敗した場合も記録する）
            $table->string('email', 255)->nullable();

            // login_succeeded / login_failed / logout（App\Enums\LoginEvent）
            $table->string('event', 50);

            // 接続元のIPアドレス（IPv6を含む）
            $table->string('ip_address', 45)->nullable();

            // ブラウザの情報
            $table->text('user_agent')->nullable();

            // 発生日時
            $table->timestamp('occurred_at')->useCurrent();

            $table->index('occurred_at');
            $table->index(['event', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
