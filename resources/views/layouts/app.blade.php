@php($theme = app(\App\Services\ThemeService::class))
<!DOCTYPE html>
<html lang="ja">
    <head>

        {{-- ==========================================================
             ページ共通のHTML設定
             ----------------------------------------------------------
             テーマに layouts/app.blade.php がある場合は、そちらを使う（D-49）。
             ========================================================== --}}

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>BlogOS</title>

        {{-- 共通の画面の見た目（public/css/blogos.css）。テーマのCSSより先に読み込み、テーマで上書きする。 --}}
        <link
            rel="stylesheet"
            href="{{ asset('css/blogos.css') }}?v={{ @filemtime(public_path('css/blogos.css')) }}"
        >

        {{-- 選んだテーマのCSSを読み込む（public/themes/{テーマ名}/css/style.css の @import の順に、1つずつ更新日時を付けて。D-49-08）。 --}}
        @foreach ($theme->stylesheets() as $stylesheet)
            <link rel="stylesheet" href="{{ $stylesheet }}">
        @endforeach

        {{-- 共通の画面の JavaScript（public/js/blogos.js。表を横スクロールなしで見せる。D-50）。 --}}
        <script src="{{ asset('js/blogos.js') }}?v={{ @filemtime(public_path('js/blogos.js')) }}" defer></script>

        {{-- 選んだテーマのJavaScriptを読み込む（public/themes/{テーマ名}/js/script.js）。 --}}
        <script
            src="{{ $theme->js() }}"
        ></script>

    </head>

    {{-- テーマのCSSで、テーマごとの指定に使えるよう、テーマ名を付ける --}}
    <body class="theme-{{ $theme->current() }}">

        {{-- ==========================================================
             BlogOS共通ヘッダー
             ----------------------------------------------------------
             トップページ・設定ページ・各種一覧・詳細ページなど、
             layouts.appを利用するすべてのページで共通表示する。
             ========================================================== --}}

        @include('layouts.header')

        {{-- ==========================================================
             各ページ固有の本文
             ========================================================== --}}

        {{-- テーマのCSSで、本文の余白・幅を決められるよう、枠を付ける（blank では何もしない） --}}
        <main class="site-main">
            @yield('content')
        </main>

    </body>
</html>
