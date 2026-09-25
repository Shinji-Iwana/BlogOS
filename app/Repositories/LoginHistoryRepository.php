<?php

namespace App\Repositories;

use App\Enums\LoginEvent;
use App\Models\LoginHistory;
use Illuminate\Database\Eloquent\Collection;

class LoginHistoryRepository
{
    /**
     * ログイン・ログアウトを1件記録する。
     *
     * パスワードは受け取らない・記録しない（BLOGOS_DECISIONS.md D-17-03）。
     */
    public function record(
        LoginEvent $event,
        ?int $userId,
        ?string $email,
        ?string $ipAddress,
        ?string $userAgent
    ): LoginHistory {
        return LoginHistory::create([
            'user_id'     => $userId,
            'email'       => $email,
            'event'       => $event,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'occurred_at' => now(),
        ]);
    }

    /**
     * 新しい順に取得する（確認画面用）。
     */
    public function getLatest(int $limit = 100): Collection
    {
        return LoginHistory::with('user')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }
}
