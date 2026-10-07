<?php

namespace App\Services\Auth;

use App\Enums\LoginEvent;
use App\Repositories\LoginHistoryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * ログイン・ログアウトの記録を担当する（BLOGOS_DECISIONS.md D-17-03）。
 *
 * 管理者のパスワードを当面変更しないため、不正なログインの有無を
 * 後から確認できるよう、成功だけでなく失敗も記録する。
 */
class LoginHistoryService
{
    public function __construct(
        protected LoginHistoryRepository $loginHistoryRepository
    ) {
    }

    public function recordSucceeded(Request $request, int $userId): void
    {
        $this->record(LoginEvent::LoginSucceeded, $request, $userId);
    }

    public function recordFailed(Request $request): void
    {
        $this->record(LoginEvent::LoginFailed, $request, null);
    }

    public function recordLocked(Request $request): void
    {
        $this->record(LoginEvent::LoginLocked, $request, null);
    }

    public function recordLogout(Request $request, ?int $userId): void
    {
        $this->record(LoginEvent::Logout, $request, $userId);
    }

    /**
     * 一番新しい記録（確認画面の「最後の記録」）
     */
    public function latest(): ?\App\Models\LoginHistory
    {
        return $this->loginHistoryRepository->latest();
    }

    /**
     * 確認画面用に、新しい順で、ページに分けて取得する。
     */
    public function paginateLatest(int $perPage): LengthAwarePaginator
    {
        return $this->loginHistoryRepository->paginateLatest($perPage);
    }

    protected function record(LoginEvent $event, Request $request, ?int $userId): void
    {
        $this->loginHistoryRepository->record(
            $event,
            $userId,
            // ログアウト時は入力がないため、ログイン中の利用者のメールアドレスを使う
            $request->input('email') ?? $request->user()?->email,
            $request->ip(),
            $request->userAgent()
        );
    }
}
