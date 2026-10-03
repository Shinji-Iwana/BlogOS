{{--
    トップページ（ironman テーマ。制作中。D-49）

    共通の画面（resources/views/dashboard/index.blade.php）の代わりに使う。
    中身（お知らせと各画面への入口）は共通の部品をそのまま使い、アークリアクターの飾りを加える。
--}}

@extends('layouts.app')

@section('content')

    @include('themes.ironman.components.reactor')

    @include('dashboard.content')

@endsection
