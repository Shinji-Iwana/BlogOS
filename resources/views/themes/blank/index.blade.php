@extends('layouts.app')

@section('content')
<p><a href="{{ route('blog-register') }}">ブログ登録ページへ</a></p>
<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button type="submit">ログアウト</button>
</form>
@endsection
