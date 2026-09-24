{{--
    設定ページ

    BlogOS全体の設定・管理機能への入口となるページ。

    現時点では設定機能が未実装のため、
    WordPress API確認用の各種ページへの導線を配置する。

    設定対象となるブログは、
    URLからblogIdを受け取るのではなく、
    SettingsControllerがblogs.is_selectedから取得する
    現在選択中のブログを使用する。
--}}

@extends('layouts.app')

@section('content')

    <h1>設定</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
    </p>


    {{-- ==========================================================
         現在選択中ブログ
         ========================================================== --}}

    @if ($blogId)

        <p>
            現在選択中ブログID：{{ $blogId }}
        </p>


        {{-- ======================================================
             WordPress API確認
             ====================================================== --}}
    <section>
        <h2>WordPress API確認</h2>

        <p>
            WordPress REST APIから取得した情報を確認します。
        </p>

        <p><a href="{{ route('api-blog-detail', $blogId) }}">ブログ情報一覧ページへ</a></p>
        <p><a href="{{ route('api-page-list', $blogId) }}">固定ページ一覧ページへ</a></p>
        <p><a href="{{ route('api-category-list', $blogId) }}">カテゴリ一覧ページへ</a></p>
        <p><a href="{{ route('api-tag-list', $blogId) }}">タグ一覧ページへ</a></p>
        <p><a href="{{ route('api-media-list', $blogId) }}">メディア一覧ページへ</a></p>
        <p><a href="{{ route('api-status-list', $blogId) }}">投稿ステータス一覧ページへ</a></p>
        <p><a href="{{ route('api-type-list', $blogId) }}">投稿タイプ一覧ページへ</a></p>
        <p><a href="{{ route('api-taxonomy-list', $blogId) }}">タクソノミー一覧ページへ</a></p>
        <p><a href="{{ route('api-author-list', $blogId) }}">投稿者情報一覧ページへ</a></p>
    </section>

    @else

        <p>
            登録されているブログがありません。
        </p>

    @endif

@endsection
