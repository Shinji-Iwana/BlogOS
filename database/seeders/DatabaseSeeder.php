<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * 本番（XServer）でも実行されるため、テスト用・初期状態のユーザーを作成しない。
     * Laravelの初期状態にあった Test User の作成は、誰でも推測できる認証情報で
     * ログインできてしまうため削除した（BLOGOS_DECISIONS.md D-17-02）。
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
        ]);
    }
}
