{{--
    WordPress API情報：エンドポイント一覧（D-11-05。メニューの「情報 → WordPress API情報」。D-63-12）

    選択中のブログのWordPress APIを、その場で呼び出して確認する。取得結果はDBに保存しない。
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPress API情報</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @if ($selectedBlog)
        <p>対象のブログ：{{ $selectedBlog->display_name }}（{{ $selectedBlog->home }}）</p>

        <ul>
            @foreach ($resources as $key => $definition)
                <li>
                    <a href="{{ route('wp-api.resources.index', $key) }}">{{ $definition['label'] }}</a>
                    <small>（{{ $definition['endpoint'] }}）</small>
                </li>
            @endforeach
        </ul>
    @else
        <p>ブログが選択されていません。</p>
    @endif

@endsection
