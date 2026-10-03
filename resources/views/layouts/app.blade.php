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

        {{-- 選んだテーマのCSSを読み込む（public/themes/{テーマ名}/css/style.css）。 --}}
        <link
            rel="stylesheet"
            href="{{ $theme->css() }}"
        >

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

        @yield('content')

    </body>
</html>
