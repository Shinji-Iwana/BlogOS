@extends('layouts.app')

@section('content')
<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button type="submit">ログアウト</button>
</form>
@endsection
