{{--
    ページの頭（文字コード・画面の幅・題名・共通とテーマの CSS と JavaScript）。D-49・D-50・D-51

    layouts/app（ログイン後の画面）と layouts/guest（ログイン画面など、ログイン前の画面）で共通。
    受け取る値：$theme（App\Services\ThemeService）
--}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'BlogOS')</title>
{{-- ブラウザから送る処理（音声の操作など）の CSRF 対策 --}}
<meta name="csrf-token" content="{{ csrf_token() }}">

{{-- 画面のパネル（トップページの横）の中に表示しているとき、ヘッダーとメニューを出さない（表示する前に決める。D-59） --}}
<script>if (window.self !== window.top) { document.documentElement.classList.add('is-embedded'); }</script>

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

{{-- 音声の操作（public/js/voice.js。マイクのボタンがある画面だけで動く。D-58）。 --}}
<script src="{{ asset('js/voice.js') }}?v={{ @filemtime(public_path('js/voice.js')) }}" defer></script>

{{-- 選んだテーマのJavaScriptを読み込む（public/themes/{テーマ名}/js/script.js）。 --}}
<script
    src="{{ $theme->js() }}"
></script>
