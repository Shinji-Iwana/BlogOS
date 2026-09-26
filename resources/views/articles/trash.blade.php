{{--
    記事のゴミ箱への移動・完全削除の確認（重大な操作。WORDPRESS_API 22章、D-09-05）
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $force ? '完全に削除する' : 'ゴミ箱へ移動する' }}</h1>

    <p><a href="{{ route('articles.show', ['type' => $type, 'id' => $article->id]) }}">記事に戻る</a></p>

    @include('partials.flash')

    <p>対象：<strong>{{ $article->title_raw ?: '（タイトルなし）' }}</strong>（{{ $article->status }}、スラッグ：{{ \App\Support\Slug::display($article->slug) }}）</p>

    @if ($force)
        <p style="color:#b00;"><strong>WordPressから完全に削除します。ゴミ箱には残らず、元に戻せません。</strong>BlogOSの管理情報・評価・履歴は残ります。</p>
    @else
        <p>WordPressのゴミ箱へ移動します。WordPressの管理画面から元に戻せます。</p>
    @endif

    <form method="POST" action="{{ route('articles.trash.destroy', ['type' => $type, 'id' => $article->id]) }}">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="force" value="{{ $force ? 1 : 0 }}">
        {{-- 画面を開いた時点の記事の版。操作の直前に、WordPress側で変更されていないかを確かめる --}}
        <input type="hidden" name="base_modified" value="{{ $article->wordpress_modified_gmt?->format('Y-m-d H:i:s') }}">

        @if ($force)
            <p><label>確認のため、記事のスラッグ（{{ \App\Support\Slug::display($article->slug) }}）を入力してください：<br><input type="text" name="confirm_slug" autocomplete="off"></label></p>
        @endif

        <p><label><input type="checkbox" name="confirmed" value="1"> 内容を確認しました</label></p>
        <button type="submit">{{ $force ? '完全に削除する' : 'ゴミ箱へ移動する' }}</button>
    </form>

@endsection
