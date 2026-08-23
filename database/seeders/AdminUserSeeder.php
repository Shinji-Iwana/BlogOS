<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'shin.rock.m.2115@gmail.com'], // ★ここをご自身のログイン用メールアドレスに変更
            [
                'name'     => 'Admin',
                'password' => Hash::make('prog-pro4649'), // ★ここを強固なパスワードに変更
            ]
        );
    }
}
