{{--
    トップページ（共通の画面。D-16-01・D-49）

    テーマに resources/views/themes/{テーマ名}/dashboard/index.blade.php がある場合は、そちらを使う。
--}}

@extends('layouts.app')

@section('content')

    @include('dashboard.content')

    {{-- 未確認のお知らせのポップアップ（D-74） --}}
    @include('notices.popup')

@endsection
