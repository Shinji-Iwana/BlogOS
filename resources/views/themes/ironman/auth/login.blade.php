{{--
    ログイン画面（ironman テーマ。D-51）

    共通の画面（resources/views/auth/login.blade.php）の代わりに使う。
    中央にアークリアクター（青。状態はログイン前のため出さない）、その下に BlogOS の名前と、ログインの入力欄（共通の auth/login-form）。
--}}

@extends('layouts.guest')

@section('title', 'ログイン（BlogOS）')

@section('content')

    <div class="login-hud">

        @include('themes.ironman.components.reactor', ['reactorState' => 'normal'])

        <h1 class="login-title">BlogOS</h1>
        <p class="login-subtitle">SYSTEM ACCESS</p>

        @include('auth.login-form')

    </div>

@endsection
