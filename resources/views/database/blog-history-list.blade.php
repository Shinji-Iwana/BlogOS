{{--
    ブログ変更履歴一覧（DB確認画面）

    履歴は変更した項目ごとに1行（BLOGOS_DATABASE.md 8-2）。
    「変更のまとまり」が同じ行は、同時に起きた変更である。
--}}

@extends('layouts.app')

@section('content')

    <h1>ブログ変更履歴一覧</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <section>
        <h2>BlogOS側の情報（blog_histories）：{{ $histories->count() }}件</h2>
        <div style="overflow-x:auto;">
            <table border="1" cellpadding="4" cellspacing="0">
                <thead>
                    <tr>
                        <th>変更日時</th><th>ブログ</th><th>項目</th><th>変更前</th><th>変更後</th>
                        <th>変更元</th><th>利用者</th><th>変更のまとまり</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($histories as $history)
                        <tr>
                            <td>{{ $history->changed_at }}</td>
                            <td>{{ $history->blog?->display_name }}</td>
                            <td>{{ $history->field }}</td>
                            <td>{{ $history->old_value }}</td>
                            <td>{{ $history->new_value }}</td>
                            <td>{{ $history->source->value }}</td>
                            <td>{{ $history->user?->name }}</td>
                            <td><small>{{ $history->change_set_id }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">記録はありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2>WordPressのサイト設定（blog_setting_histories）：{{ $settingHistories->count() }}件</h2>
        <div style="overflow-x:auto;">
            <table border="1" cellpadding="4" cellspacing="0">
                <thead>
                    <tr>
                        <th>変更日時</th><th>ブログ</th><th>キー</th><th>項目</th><th>変更前</th><th>変更後</th>
                        <th>変更元</th><th>変更のまとまり</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settingHistories as $history)
                        <tr>
                            <td>{{ $history->changed_at }}</td>
                            <td>{{ $history->blog?->display_name }}</td>
                            <td>{{ $history->setting?->key }}</td>
                            <td>{{ $history->field }}</td>
                            <td>{{ $history->old_value }}</td>
                            <td>{{ $history->new_value }}</td>
                            <td>{{ $history->source->value }}</td>
                            <td><small>{{ $history->change_set_id }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">記録はありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

@endsection
