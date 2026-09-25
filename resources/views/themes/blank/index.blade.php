{{--
    トップページ（blankテーマ：装飾なし。D-16-01）

    テーマは見た目だけを担当する。表示するデータは DashboardController が渡す。
--}}

@extends('layouts.app')

@section('content')

@if ($errors->any())
    <div style="color:#b00;">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if ($blogs->isNotEmpty())

    <p>
        現在のブログ：
        {{ $selectedBlog?->display_name ?? '（未選択。画面上部のボタンから選択してください）' }}
    </p>

    @if (session('status'))
        <p style="color:#070;">{{ session('status') }}</p>
    @endif

    @if ($selectedBlog)
        @include('partials.sync-status')
    @endif

    <p><a href="{{ route('blogs.create') }}">ブログを登録する</a></p>
    <p><a href="{{ route('database-blog-list') }}">ブログ一覧ページへ</a></p>
    <p><a href="{{ route('database-blog-history-list') }}">ブログ変更履歴一覧ページへ</a></p>

    {{-- 選択中のブログを対象とするページ --}}
    @if ($selectedBlog)
        <p>
            記事：<a href="{{ route('articles.index', ['type' => 'posts']) }}">投稿</a>
            ・<a href="{{ route('articles.index', ['type' => 'pages']) }}">固定ページ</a>
            ・<a href="{{ route('drafts.index') }}">編集案</a>
            ・<a href="{{ route('push-operations.index') }}">反映記録</a>
        </p>
        <p><a href="{{ route('database.wordpress-records.tables') }}">取り込んだWordPressのデータ（DB確認）へ</a></p>
        <p><a href="{{ route('wp-api.home') }}">WordPress API確認ページへ</a></p>
        <p><a href="{{ route('api-site-search') }}">サイト内検索ページへ</a></p>
    @endif

    <p><a href="{{ route('api-analytics-info') }}">Google Analytics情報一覧ページへ</a></p>
    <p><a href="{{ route('api-search-console-info') }}">Search Console情報一覧ページへ</a></p>
    <p><a href="{{ route('api-adsense-info') }}">AdSense情報一覧ページへ</a></p>

@else

    {{-- ブログが1件も登録されていない場合は、ブログ登録へ誘導する（DEVELOPMENT_RULES 15章） --}}
    <p>登録されているブログがありません。</p>
    <p><a href="{{ route('blogs.create') }}">ブログを登録する</a></p>

@endif

<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button type="submit">ログアウト</button>
</form>

@endsection
