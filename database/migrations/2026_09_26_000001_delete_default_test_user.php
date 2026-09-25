<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Laravelの初期状態の DatabaseSeeder が作成した Test User を削除する。
     *
     * Test User は、誰でも推測できる認証情報（Factoryの既定のパスワード）で
     * ログインできてしまう。Seederからは作成処理を削除したが、既に作成された
     * アカウントはローカル・XServerのどちらにも残っている可能性があるため、
     * Migrationとして実行し、どの環境でも確実に削除する（BLOGOS_DECISIONS.md D-17-02）。
     *
     * 誤って別の利用者を消さないよう、メールアドレスと名前の両方が一致するものだけを対象にする。
     */
    public function up(): void
    {
        $userIds = DB::table('users')
            ->where('email', 'test@example.com')
            ->where('name', 'Test User')
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        // ログイン中のセッションが残っていれば、あわせて無効にする
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();

        DB::table('users')->whereIn('id', $userIds)->delete();
    }

    /**
     * Reverse the migrations.
     *
     * 削除した Test User は復元しない（復元すると問題が再発するため）。
     */
    public function down(): void
    {
        //
    }
};
