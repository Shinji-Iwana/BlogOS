{{--
    カテゴリの一覧（D-53）

    親子の順に、名前・説明・スラッグ・記事の数を並べる。各カテゴリの更新（WordPress へ反映）は「編集」から。
    新しいカテゴリは「カテゴリの立ち上げ」で作る。
--}}

@extends('layouts.app')

@section('content')

    <h1>カテゴリ：{{ $categories->count() }}件</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('launches.index') }}">カテゴリの立ち上げ（新しいカテゴリを作る）</a>
        ・<a href="{{ route('database.wordpress-records.index', ['table' => 'categories']) }}">DBの値と変更履歴</a>
    </p>

    @include('partials.flash')

    <p class="text-muted">
        名前・説明・スラッグ・親カテゴリは「編集」から変え、承認して WordPress へ反映します。
        スラッグを変えると、カテゴリのページと、そのカテゴリ・子カテゴリの全記事の URL が変わります（編集の画面で警告し、確認します）。
        説明は、今のブログのテーマ（Theme-SI-Original）ではカテゴリのページに表示していません。AIOSEO の設定によっては、カテゴリのページの検索結果の説明文に使われます。BlogOS では、教材を AI で調べるときの手がかりに使います。
    </p>

    <table class="data" style="width:100%;">
        <thead>
            <tr>
                <th>名前</th>
                <th>説明</th>
                <th>スラッグ</th>
                <th>記事</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td style="padding-left:{{ 4 + $category->depth * 20 }}px;">
                        @if ($category->depth > 0)<span class="text-muted">└ </span>@endif
                        @if ($category->link)
                            <a href="{{ $category->link }}" target="_blank">{{ $category->name }}</a>
                        @else
                            {{ $category->name }}
                        @endif
                    </td>
                    <td>
                        @if (filled($category->description))
                            {{ \Illuminate\Support\Str::limit(strip_tags((string) $category->description), 80) }}
                        @else
                            <span class="text-faint">（未設定）</span>
                        @endif
                    </td>
                    <td>{{ \App\Support\Slug::display($category->slug) }}</td>
                    <td style="text-align:right;">{{ $category->posts_count }}</td>
                    <td style="white-space:nowrap;"><a href="{{ route('terms.edit', ['type' => 'categories', 'id' => $category->id]) }}">編集</a></td>
                </tr>
            @empty
                <tr><td colspan="5">カテゴリがありません（同期すると取り込みます）。</td></tr>
            @endforelse
        </tbody>
    </table>

@endsection
