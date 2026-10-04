{{--
    ログイン画面（D-51）

    ログイン前の画面の枠（layouts/guest）で、選んだテーマの見た目にする。
    テーマに themes/{テーマ名}/auth/login.blade.php がある場合は、そちらを使う。
--}}

@extends('layouts.guest')

@section('title', 'ログイン（BlogOS）')

@section('content')

    <h1>ログイン</h1>

    @include('auth.login-form')

@endsection
