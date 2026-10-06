{{--
    設定ページ

    BlogOS全体の設定・管理機能への入口となるページ。

    認証情報と、ログイン履歴への導線を配置する。
    画面のテーマは、メニューの「SETTING 設定 → 画面のテーマ」のポップアップで切り替える（D-57）。

    対象のブログは、URLで受け取らず、選択中のブログ（blogs.is_selected）とする
    （BLOGOS_DECISIONS.md D-02-05）。$selectedBlog は ShareCurrentBlog が共有している。
--}}

@extends('layouts.app')

@section('content')

    <h1>設定</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
    </p>


    {{-- ==========================================================
         選択中のブログ（WordPress API情報は、メニューの「情報 → WordPress API」から開く。D-63-12）
         ========================================================== --}}

    @if ($selectedBlog)

        <p>
            現在選択中のブログ：{{ $selectedBlog->display_name }}（ID：{{ $selectedBlog->id }}）
        </p>

        <p><a href="{{ route('blogs.credentials.edit') }}">認証情報（WordPressのApplication Password）ページへ</a></p>

    @elseif ($blogs->isNotEmpty())

        <p>
            ブログが選択されていません。画面上部のブログ切り替えから選択してください。
        </p>

    @else

        <p>
            登録されているブログがありません。
        </p>

    @endif


    {{-- ==========================================================
         セキュリティ
         ========================================================== --}}

    <section class="panel">
        <h2>セキュリティ</h2>

        <p><a href="{{ route('database.login-histories.index') }}">ログイン履歴ページへ</a></p>
    </section>

@endsection
