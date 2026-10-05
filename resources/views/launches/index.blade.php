{{--
    カテゴリの立ち上げの一覧と、新しい立ち上げ（D-41）
--}}

@extends('layouts.app')

@section('content')

    <h1>カテゴリの立ち上げ</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a>・<a href="{{ route('topics.index') }}">記事の企画</a></p>

    @include('partials.flash')

    <p class="text-muted">
        親カテゴリの下に子カテゴリを立ち上げ、①親カテゴリ → ②子カテゴリ → ③記事の企画（子カテゴリごとに10件） → ④記事の編集案 → ⑤子ロードマップの編集案、の順に進めます。
        各段階で、人が確認してから次へ進みます。公開（カテゴリの作成・まとめて公開）と親ロードマップは、次の段階で追加します。
    </p>

    <section class="panel">
    <h2>進行中・これまでの立ち上げ</h2>
    @if ($launches->isEmpty())
        <p>ありません。</p>
    @else
        <ul>
            @foreach ($launches as $launch)
                <li><a href="{{ route('launches.show', ['id' => $launch->id]) }}">{{ $launch->parentCategory?->name }}</a>（子カテゴリ {{ $launch->children->count() }}件・{{ \App\Models\CategoryLaunch::STATUSES[$launch->status] ?? $launch->status }}・{{ \App\Support\DisplayTime::format($launch->created_at, 'Y-m-d') }}）</li>
            @endforeach
        </ul>
    @endif
    </section>

    <section class="panel">
    <h2>新しく立ち上げる</h2>
    <form method="GET" action="{{ route('launches.index') }}">
        <p>
            ① 親カテゴリ
            <select name="parent_id" onchange="this.form.submit()">
                <option value="">（選ぶ）</option>
                @foreach ($parents as $option)
                    <option value="{{ $option->id }}" @selected($parent?->id === $option->id)>{{ $option->name }}（{{ $option->slug }}）</option>
                @endforeach
            </select>
        </p>
    </form>

    <details>
        <summary>新しい技術（親カテゴリ）を始める</summary>
        <form method="POST" action="{{ route('launches.parents.store') }}" onsubmit="return confirm('この親カテゴリを WordPress に作りますか？');">
            @csrf
            @include('partials.selected-blog-field')
            <p>
                名前 <input type="text" name="name" value="{{ old('name') }}" style="width:200px;" placeholder="例：Go">
                スラッグ <input type="text" name="slug" value="{{ old('slug') }}" style="width:120px;" placeholder="例：go">
                <button type="submit">WordPress に作る</button>
            </p>
            <p class="text-muted">記事がまだないカテゴリは、WordPress の画面には表示されません。親ロードマップの固定ページは、子ロードマップを公開するときに下書きとして作ります。</p>
        </form>
    </details>

    @if ($parent)
        <form method="POST" action="{{ route('launches.store') }}">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="parent_id" value="{{ $parent->id }}">
            <p>② 立ち上げる子カテゴリ（<a href="{{ route('topics.index', ['category_id' => $parent->id]) }}">足りない子カテゴリを企画する</a>）</p>
            @include('launches.partials.child-options', ['options' => $options])
            <p><button type="submit">立ち上げを始める</button></p>
        </form>
    @endif
    </section>

@endsection
