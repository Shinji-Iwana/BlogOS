<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ブログごとのWordPress認証情報を保存する blog_credentials を作成する
     * （BLOGOS_DATABASE.md 5-3、BLOGOS_DECISIONS.md D-03-02）。
     *
     * blogs と同じ表に置くと、一覧の取得などで誤って一緒に出力する危険があるため、別の表にする。
     * secret（Application Password）は Model の encrypted キャストで暗号化して保存する。
     */
    public function up(): void
    {
        Schema::create('blog_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->unique()->constrained('blogs')->cascadeOnDelete();

            // 認証方式（現在は application_password だけ）
            $table->string('auth_type', 50)->default('application_password');

            // WordPressのユーザー名
            $table->string('username', 255);

            // Application Password（暗号化した値。暗号文は長くなるためTEXT）
            $table->text('secret');

            // 最後に接続確認に成功した日時
            $table->timestamp('verified_at')->nullable();

            // 最後に失敗した日時と内容（認証情報そのものは含めない）
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_credentials');
    }
};
