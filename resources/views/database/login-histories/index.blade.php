{{--
    ログイン履歴（DB確認画面）

    login_histories に記録された、ログインの成功・失敗とログアウトを新しい順に表示する。
    心当たりのない成功や、繰り返しの失敗がないかを確認するための画面
    （BLOGOS_DECISIONS.md D-17-03）。
--}}

@extends('layouts.app')

@section('content')

    <h1>ログイン履歴</h1>

    <p>
        <a href="{{ route('settings') }}">設定ページに戻る</a>
    </p>

    <p>表示件数：{{ $histories->count() }}件（新しい順、最大200件）</p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>結果</th>
                    <th>メールアドレス</th>
                    <th>利用者</th>
                    <th>IPアドレス</th>
                    <th>ブラウザ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($histories as $history)
                    <tr>
                        <td>{{ $history->occurred_at }}</td>
                        <td>
                            @switch ($history->event)
                                @case (\App\Enums\LoginEvent::LoginSucceeded)
                                    ログイン成功
                                    @break
                                @case (\App\Enums\LoginEvent::LoginFailed)
                                    <strong>ログイン失敗</strong>
                                    @break
                                @case (\App\Enums\LoginEvent::LoginLocked)
                                    <strong>ログイン停止中の試行</strong>
                                    @break
                                @case (\App\Enums\LoginEvent::Logout)
                                    ログアウト
                                    @break
                            @endswitch
                        </td>
                        <td>{{ $history->email }}</td>
                        <td>{{ $history->user?->name }}</td>
                        <td>{{ $history->ip_address }}</td>
                        <td>{{ $history->user_agent }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">記録はありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
