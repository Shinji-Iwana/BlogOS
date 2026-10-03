{{--
    カテゴリごとのアイキャッチ（D-32-03）：技術（カテゴリ）ごとの共通の画像。子のカテゴリに設定がなければ、親の設定を使う
--}}

@extends('layouts.app')

@section('content')

    <h1>カテゴリごとのアイキャッチ（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('images.index') }}">画像の一覧に戻る</a>
    </p>

    @include('partials.flash')

    <p class="text-muted">
        新しい記事には、記事のカテゴリ（なければ親のカテゴリ）のアイキャッチを設定します（記事の作成への組み込みは、HTMLのルールと一緒に行います）。
        選べるのは、WordPress のメディアにある画像です。新しい技術のカテゴリで画像が必要なときは、<a href="{{ route('images.index', ['kind' => 'eyecatch']) }}">画像</a>の画面でアイキャッチを作り（画像モデル、またはアップロード）、WordPress に登録してから、ここで選んでください。
        「今の記事で多い画像」は、そのカテゴリの記事で、いちばん多く使われているアイキャッチです。
    </p>

    <form method="POST" action="{{ route('images.eyecatches.update') }}">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')

        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>カテゴリ</th><th>記事数</th><th>今の記事で多い画像</th><th>アイキャッチ</th></tr></thead>
                <tbody>
                    @foreach ($categories as $category)
                        @php
                            $setting = $settings->get($category->id);
                            $usedMedia = isset($used[$category->id]) ? $media->get($used[$category->id]['media_id']) : null;
                            $current = old("eyecatch.{$category->id}", $setting?->media_id);
                        @endphp
                        <tr>
                            <td>{{ str_repeat('　', (int) ($category->depth ?? 0)) }}{{ $category->name }}</td>
                            <td style="text-align:right;">{{ $category->posts_count }}</td>
                            <td>
                                @if ($usedMedia)
                                    <img src="{{ $usedMedia->source_url }}" alt="" style="width:60px; height:60px; object-fit:cover; vertical-align:middle;" loading="lazy">
                                    {{ $used[$category->id]['count'] }}記事
                                    @if ((int) $current !== $usedMedia->id)
                                        <button class="btn-secondary" type="button" onclick="document.getElementById('eyecatch-{{ $category->id }}').value = '{{ $usedMedia->id }}'">これにする</button>
                                    @endif
                                @else - @endif
                            </td>
                            <td>
                                @if ($setting?->media)
                                    <img src="{{ $setting->media->source_url }}" alt="" style="width:60px; height:60px; object-fit:cover; vertical-align:middle;" loading="lazy">
                                @endif
                                <select name="eyecatch[{{ $category->id }}]" id="eyecatch-{{ $category->id }}" style="max-width:320px;">
                                    <option value="">（親のカテゴリの設定を使う）</option>
                                    @foreach ($media as $item)
                                        <option value="{{ $item->id }}" @selected((int) $current === $item->id)>#{{ $item->wordpress_id }} {{ $item->title_raw ?: basename((string) $item->source_url) }}（{{ $item->width }}×{{ $item->height }}）</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p><button type="submit">保存する</button></p>
    </form>

@endsection
