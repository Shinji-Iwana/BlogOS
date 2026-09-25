{{--
    設定ページ

    BlogOS全体の設定・管理機能への入口となるページ。

    現時点では設定機能が未実装のため、
    WordPress API確認用の各種ページと、ログイン履歴への導線を配置する。

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
         WordPress API確認（選択中のブログが対象）
         ========================================================== --}}

    @if ($selectedBlog)

        <p>
            現在選択中のブログ：{{ $selectedBlog->display_name }}（ID：{{ $selectedBlog->id }}）
        </p>

        <p><a href="{{ route('blogs.credentials.edit') }}">認証情報（WordPressのApplication Password）ページへ</a></p>

        <section>
            <h2>WordPress API確認</h2>

            <p>
                WordPress REST APIから取得した情報を確認します。
            </p>

            <p><a href="{{ route('wp-api.home') }}">エンドポイント一覧ページへ</a></p>
        </section>

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

    <section>
        <h2>セキュリティ</h2>

        <p><a href="{{ route('database.login-histories.index') }}">ログイン履歴ページへ</a></p>
    </section>

@endsection
