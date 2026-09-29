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

    {{-- OpenAI の残高の見込みのお知らせ（D-31-04） --}}
    @if ($selectedBlog)
        @include('partials.ai-credit-notice')
    @endif

    {{-- API実行の料金表のお知らせ（D-31-03） --}}
    @if ($selectedBlog && ($priceNotice['pending'] || $priceNotice['failed'] || $priceNotice['applied']))
        <p style="color:#b60;">
            AIの料金表：
            @if ($priceNotice['failed'])<strong style="color:#b00;">公式のページから読み取れなかった料金があります。</strong>@endif
            @if ($priceNotice['pending'])値下がりの確認待ちが{{ $priceNotice['pending'] }}件あります。@endif
            @if ($priceNotice['applied'])直近7日に{{ $priceNotice['applied'] }}件の料金を変更しました。@endif
            <a href="{{ route('ai.settings.edit') }}#prices">料金表を確認する</a>
        </p>
    @endif

    {{-- 公開された記事へのリンクの切り替え（D-39） --}}
    @if (($linkSwitchDrafts ?? 0) > 0)
        <p style="color:#b60;">
            公開された記事へのリンクに切り替えられる記事が{{ $linkSwitchDrafts }}件あります。
            <a href="{{ route('drafts.link-switch') }}">確認して反映する</a>
        </p>
    @endif

    {{-- WordPress の更新（D-38） --}}
    @if (($wordpressNotice['updates'] ?? 0) > 0 || ($wordpressNotice['closed'] ?? 0) > 0)
        <p style="color:#b00;">
            WordPress：@if ($wordpressNotice['updates'])更新が{{ $wordpressNotice['updates'] }}件あります。@endif
            @if ($wordpressNotice['closed'])公開停止になったプラグインが{{ $wordpressNotice['closed'] }}件あります。@endif
            <a href="{{ route('wordpress-updates.index') }}">WordPress の更新を確認する</a>
        </p>
    @endif

    {{-- アフィリエイトのリンクの確認（D-33-09） --}}
    @if (($affiliateSuspects ?? 0) > 0)
        <p style="color:#b00;">
            アフィリエイトのリンク：提携終了の疑いがあるプログラムが{{ $affiliateSuspects }}件あります。
            <a href="{{ route('materials.programs.index') }}">アフィリエイトのプログラムを確認する</a>
        </p>
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
            ・<a href="{{ route('ai.generations.index') }}">AI実行記録</a>
            ・<a href="{{ route('ai.generations.create', ['mode' => 'new_article']) }}">AIで新規記事の案を作る</a>
            ・<a href="{{ route('ai.batches.index') }}">AIのまとめて実行</a>
            ・<a href="{{ route('ai.settings.edit') }}">AIの設定</a>
            ・<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>
            ・<a href="{{ route('management-suggestions.index') }}">管理情報の案の確認</a>
        </p>
        <p>
            収益：<a href="{{ route('materials.index') }}">教材（書籍・Udemy・スクール・問題集）</a>
            ・<a href="{{ route('materials.programs.index') }}">アフィリエイトのプログラム</a>
            ・<a href="{{ route('materials.suggestions.index') }}">教材の案の確認</a>
            ・<a href="{{ route('materials.reviews.index') }}">記事の教材の見直し</a>
        </p>
        <p>
            画像：<a href="{{ route('images.index') }}">画像（図解・イラスト・アイキャッチ・スクリーンショット）</a>
            ・<a href="{{ route('images.eyecatches') }}">カテゴリごとのアイキャッチ</a>
        </p>
        <p><a href="{{ route('database.wordpress-records.tables') }}">取り込んだWordPressのデータ（DB確認）へ</a></p>
        <p><a href="{{ route('wp-api.home') }}">WordPress API確認ページへ</a></p>
        <p><a href="{{ route('api-site-search') }}">サイト内検索ページへ</a></p>
    @endif

    @if ($selectedBlog)
        <p><a href="{{ route('analytics.index') }}">分析（GA4・Search Console・AdSense）</a>・<a href="{{ route('google.index-status') }}">インデックスの登録状態</a>・<a href="{{ route('wordpress-updates.index') }}">WordPress の更新</a>・<a href="{{ route('articles.titles') }}">タイトル・メタディスクリプションの改善の候補</a>・<a href="{{ route('google.settings') }}">Google連携の設定</a></p>
    @endif

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
