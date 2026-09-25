{{--
    ブログ詳細（DB確認画面）

    blogs・blog_settings・blog_credentials の保存内容を表示する。
    Application Password は表示しない（D-03-03）。
--}}

@extends('layouts.app')

@section('content')

    <h1>ブログ詳細</h1>

    <p><a href="{{ route('database-blog-list') }}">ブログ一覧に戻る</a></p>

    @if (session('status'))
        <p style="color:#070;">{{ session('status') }}</p>
    @endif

    {{-- 登録直後の確認結果（WORDPRESS_API 29章） --}}
    @if (session('registration'))
        @php($registration = session('registration'))
        <section>
            <h2>登録時の確認結果</h2>
            <p>認証したWordPressユーザー：{{ $registration['wordpress_user'] }}（{{ implode(', ', $registration['wordpress_roles']) }}）</p>
            <p>
                BlogOS連携用のWordPress側の拡張：
                @if ($registration['connector_extension'] === true)
                    有効
                @elseif ($registration['connector_extension'] === false)
                    無効（新規作成がタイムアウトした場合は、人が照合します）
                @else
                    判定できませんでした（投稿がありません）
                @endif
            </p>
        </section>
    @endif

    @if ($blog === null)

        <p>指定されたブログは見つかりませんでした。</p>

    @else

        <section>
            <h2>BlogOS側の情報（blogs）</h2>
            <table border="1" cellpadding="4" cellspacing="0">
                <tr><th>ID</th><td>{{ $blog->id }}</td></tr>
                <tr><th>表示名</th><td>{{ $blog->display_name }}</td></tr>
                <tr><th>ホームURL</th><td>{{ $blog->home }}</td></tr>
                <tr><th>品質基準</th><td>{{ $blog->quality_profile ?? '（未設定）' }}</td></tr>
                <tr><th>選択中</th><td>{{ $blog->is_selected ? '○' : '' }}</td></tr>
                <tr><th>アーカイブ日時</th><td>{{ $blog->archived_at }}</td></tr>
                <tr><th>登録日時</th><td>{{ $blog->created_at }}</td></tr>
                <tr><th>更新日時</th><td>{{ $blog->updated_at }}</td></tr>
            </table>
        </section>

        <section>
            <h2>認証情報（blog_credentials）</h2>
            @if ($credential)
                <table border="1" cellpadding="4" cellspacing="0">
                    <tr><th>方式</th><td>{{ $credential->auth_type }}</td></tr>
                    <tr><th>ユーザー名</th><td>{{ $credential->username }}</td></tr>
                    <tr><th>Application Password</th><td>設定済み（表示しません）</td></tr>
                    <tr><th>最終確認日時</th><td>{{ $credential->verified_at }}</td></tr>
                    <tr><th>最後の失敗</th><td>{{ $credential->last_failed_at }} {{ $credential->last_error }}</td></tr>
                </table>
            @else
                <p>未設定です。</p>
            @endif
        </section>

        <section>
            <h2>WordPressのサイト設定（blog_settings）</h2>
            <table border="1" cellpadding="4" cellspacing="0">
                <thead>
                    <tr><th>キー</th><th>値</th><th>最終照合日時</th></tr>
                </thead>
                <tbody>
                    @forelse ($settings as $setting)
                        <tr>
                            <td>{{ $setting->key }}</td>
                            <td>{{ $setting->value }}</td>
                            <td>{{ $setting->synced_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">まだ取得していません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    @endif

@endsection
