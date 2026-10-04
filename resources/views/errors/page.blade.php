{{--
    エラーの画面の共通の形（D-52）。errors/{番号}.blade.php から読み込む。

    ログイン前でも出るため、ヘッダーのない枠（layouts.guest）で、選んだテーマの見た目にする。
    受け取る値：$code（例：404）、$heading（何が起きたか）、$detail（どうすればよいか）
    400番台のエラーで、BlogOS が日本語の理由を添えている場合（abort(404, 'ブログが選択されていません。') など）は、その理由も出す。
    500番台は、内部の情報を出さないよう、理由を出さない。
--}}

@extends('layouts.guest')

@section('title', $code . ' ' . $heading . '（BlogOS）')

@section('content')

    <section class="panel error-page" style="max-width:640px; margin-left:auto; margin-right:auto;">
        <h2 data-code="ERROR {{ $code }}">{{ $heading }}</h2>

        {{-- BlogOS が添える理由は日本語。Laravel が自動で付ける英語の理由（The route ... could not be found. など）は出さない --}}
        @if ($code < 500 && isset($exception) && preg_match('/[^\x00-\x7F]/', $exception->getMessage()))
            <p class="text-error">{{ $exception->getMessage() }}</p>
        @endif

        <p>{{ $detail }}</p>

        <p>
            @auth
                <a href="{{ url('/') }}" class="button-link">トップページへ</a>
            @else
                <a href="{{ route('login') }}" class="button-link">ログイン画面へ</a>
            @endauth
        </p>
    </section>

@endsection
