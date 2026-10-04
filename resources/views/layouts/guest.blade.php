@php($theme = app(\App\Services\ThemeService::class))
<!DOCTYPE html>
<html lang="ja">
    <head>

        {{-- ==========================================================
             ログイン前の画面（ログイン画面など）の枠（D-51）
             ----------------------------------------------------------
             layouts/app と同じく、選んだテーマの CSS・JavaScript を読み込む。
             ログイン前のため、ヘッダー（設定・ブログの切り替え）は出さない。
             ========================================================== --}}

        @include('layouts.head')

    </head>

    <body class="theme-{{ $theme->current() }} page-guest">

        <main class="site-main">
            @yield('content')
        </main>

    </body>
</html>
