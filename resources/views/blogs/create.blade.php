{{--
    ブログ登録（BLOGOS_WORDPRESS_API.md 29章）

    入力：ブログのURL、WordPressのユーザー名、Application Password、品質基準（ブログ別の定義）。
    サイト名・ホームURLなどは、サーバー側でWordPressから取得して保存する。
--}}

@extends('layouts.app')

@section('content')

    <h1>ブログ登録</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @if ($errors->any())
        <div style="color:#b00;">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('blogs.store') }}">
        @csrf

        <p>
            <label>
                ブログのURL<br>
                <input type="url" name="url" value="{{ old('url') }}" placeholder="https://example.com" required style="width:100%; max-width:480px;">
            </label>
        </p>

        <p>
            <label>
                WordPressのユーザー名<br>
                <input type="text" name="username" value="{{ old('username') }}" autocomplete="off" required style="width:100%; max-width:480px;">
            </label>
        </p>

        <p>
            <label>
                Application Password<br>
                <input type="password" name="application_password" autocomplete="new-password" required style="width:100%; max-width:480px;">
            </label><br>
            <small>
                WordPressの「ユーザー → プロフィール → アプリケーションパスワード」で、「BlogOS」という名前で発行したものを入力してください。
                登録後は画面に表示しません。
            </small>
        </p>

        <p>
            <label>
                品質基準（ブログ別の定義）<br>
                <select name="quality_profile">
                    <option value="">（未設定）</option>
                    @foreach ($qualityProfiles as $profile)
                        <option value="{{ $profile }}" @selected(old('quality_profile') === $profile)>{{ $profile }}</option>
                    @endforeach
                </select>
            </label>
        </p>

        <p>
            <button type="submit">確認して登録する</button>
        </p>
    </form>

@endsection
