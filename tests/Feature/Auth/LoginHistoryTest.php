<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEvent;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ログイン履歴の記録（BLOGOS_DECISIONS.md D-17-03）。
 */
class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $history = LoginHistory::sole();
        $this->assertSame(LoginEvent::LoginSucceeded, $history->event);
        $this->assertSame($user->id, $history->user_id);
        $this->assertSame($user->email, $history->email);
    }

    public function test_failed_login_is_recorded_without_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $history = LoginHistory::sole();
        $this->assertSame(LoginEvent::LoginFailed, $history->event);
        $this->assertNull($history->user_id);
        $this->assertSame($user->email, $history->email);

        // パスワードはどの列にも保存しない
        $this->assertStringNotContainsString(
            'wrong-password',
            json_encode($history->getAttributes())
        );
    }

    /**
     * 5回失敗したら止め、正しいパスワードでも1分間はログインできない（D-17-06）。
     */
    public function test_login_is_locked_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(5, LoginHistory::where('event', LoginEvent::LoginFailed)->count());
        $this->assertSame(1, LoginHistory::where('event', LoginEvent::LoginLocked)->count());

        // 1分経過すると、再びログインできる
        $this->travel(61)->seconds();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $history = LoginHistory::sole();
        $this->assertSame(LoginEvent::Logout, $history->event);
        $this->assertSame($user->id, $history->user_id);
    }

    public function test_login_history_page_is_shown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('database.login-histories.index'))
            ->assertOk()
            ->assertSee('ログイン履歴');
    }
}
