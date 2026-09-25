<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * BlogOSの管理者を登録する。
     *
     * メールアドレスとパスワードは .env の ADMIN_EMAIL・ADMIN_PASSWORD から読む。
     * リポジトリが公開のため、認証情報をこのファイルに書いてはならない
     * （BLOGOS_DECISIONS.md D-17-01）。
     *
     * 同じメールアドレスの利用者が既にいる場合は、名前とパスワードを更新する。
     */
    public function run(): void
    {
        $email = config('blogos.admin.email');
        $password = config('blogos.admin.password');

        // 空のパスワードで管理者が作られることを防ぐため、未設定なら処理を止める
        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                '.env に ADMIN_EMAIL と ADMIN_PASSWORD を設定してから実行してください。'
            );
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin',
                'password' => Hash::make($password),
            ]
        );
    }
}
