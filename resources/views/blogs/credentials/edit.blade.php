{{--
    認証情報（選択中のブログ）

    登録済みの Application Password は表示しない（D-03-03）。変更は上書きだけ。
--}}

@extends('layouts.app')

@section('content')

    <h1>認証情報</h1>

    <p><a href="{{ route('settings') }}">設定ページに戻る</a></p>

    <p>対象のブログ：{{ $blog->display_name }}（{{ $blog->home }}）</p>

    @if (session('status'))
        <p style="color:#070;">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <div style="color:#b00;">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section>
        <h2>現在の状態</h2>

        @if ($credential)
            <p>設定済み（ユーザー名：{{ $credential->username }}）</p>
            <p>最終確認日時：{{ $credential->verified_at ?? '未確認' }}</p>
            @if ($credential->last_failed_at)
                <p>最後の失敗：{{ $credential->last_failed_at }}（{{ $credential->last_error }}）</p>
            @endif

            <form method="POST" action="{{ route('blogs.credentials.verify') }}">
                @csrf
                @include('partials.selected-blog-field')
                <button type="submit">接続確認</button>
            </form>
        @else
            <p>未設定です。</p>
        @endif
    </section>

    <section>
        <h2>更新（上書き）</h2>

        <form method="POST" action="{{ route('blogs.credentials.update') }}">
            @csrf
            @method('PUT')
            @include('partials.selected-blog-field')

            <p>
                <label>
                    WordPressのユーザー名<br>
                    <input type="text" name="username" value="{{ old('username', $credential?->username) }}" autocomplete="off" required>
                </label>
            </p>

            <p>
                <label>
                    Application Password<br>
                    <input type="password" name="application_password" autocomplete="new-password" required>
                </label>
            </p>

            <p><button type="submit">接続を確認して保存する</button></p>
        </form>
    </section>

@endsection
