<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Seederが推測できる認証情報のユーザーを作らないこと（D-17-01、D-17-02）。
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_only_the_admin_from_config(): void
    {
        config([
            'blogos.admin.email'    => 'admin@example.test',
            'blogos.admin.password' => 'secret-from-env',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertFalse(User::where('email', 'test@example.com')->exists());

        $admin = User::sole();
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue(Hash::check('secret-from-env', $admin->password));
    }

    public function test_seeder_stops_when_admin_credentials_are_missing(): void
    {
        config([
            'blogos.admin.email'    => null,
            'blogos.admin.password' => null,
        ]);

        $this->expectException(RuntimeException::class);

        $this->seed(DatabaseSeeder::class);
    }
}
