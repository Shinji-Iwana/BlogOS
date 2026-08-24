@extends('layouts.app')

@section('content')
<p><a href="{{ route('blog-register') }}">ブログ登録ページへ</a></p>
<p><a href="{{ route('blog-list') }}">ブログ一覧ページへ</a></p>
<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button type="submit">ログアウト</button>
</form>
@endsection
