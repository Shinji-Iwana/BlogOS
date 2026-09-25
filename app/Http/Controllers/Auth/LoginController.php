<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * ログインの試行回数の制限（BLOGOS_DECISIONS.md D-17-06）
     *
     * 同じメールアドレスとIPアドレスの組み合わせで、この回数だけ失敗したら止める。
     */
    protected const MAX_ATTEMPTS = 5;

    /**
     * 止める時間（秒）
     */
    protected const DECAY_SECONDS = 60;

    public function __construct(
        protected LoginHistoryService $loginHistoryService
    ) {
    }

    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = $this->throttleKey($request);

        // 制限中は、パスワードの照合をせずに止める（止めた試行も記録する）
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->loginHistoryService->recordLocked($request);

            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "ログインの試行回数が上限に達しました。{$seconds}秒後にもう一度お試しください。",
            ])->onlyInput('email');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);

            $request->session()->regenerate();

            $this->loginHistoryService->recordSucceeded($request, Auth::id());

            return redirect()->intended('/');
        }

        RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        $this->loginHistoryService->recordFailed($request);

        return back()->withErrors([
            'email' => 'メールアドレスまたはパスワードが正しくありません。',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        // Auth::logout() の後は利用者を特定できないため、先に記録する
        $this->loginHistoryService->recordLogout($request, Auth::id());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * 試行回数を数える単位（メールアドレス＋IPアドレス）
     */
    protected function throttleKey(Request $request): string
    {
        return 'login:' . Str::lower((string) $request->input('email')) . '|' . $request->ip();
    }
}
