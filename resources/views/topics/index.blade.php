{{--
    記事の企画（D-40）
--}}

@extends('layouts.app')

@section('content')

    <h1>記事の企画（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a>・<a href="{{ route('launches.index') }}">カテゴリの立ち上げ</a>・<a href="{{ route('drafts.index') }}">編集案の一覧</a></p>

    @include('partials.flash')
    @include('partials.ai-credit-notice')

    <fieldset style="max-width:900px;">
        <legend>AIで企画する</legend>
        <form method="POST" action="{{ route('topics.store') }}">
            @csrf
            @include('partials.selected-blog-field')
            <p>
                <label>カテゴリ
                    <select name="category_id">
                        @foreach ($categories->whereNull('parent_id') as $parent)
                            <option value="{{ $parent->id }}" @selected($selected === $parent->id)>{{ $parent->name }}（{{ $counts[$parent->id] ?? 0 }}記事）</option>
                            @foreach ($categories->where('parent_id', $parent->id) as $child)
                                <option value="{{ $child->id }}" @selected($selected === $child->id)>　└ {{ $child->name }}（{{ $counts[$child->id] ?? 0 }}記事）</option>
                            @endforeach
                        @endforeach
                    </select>
                </label>
            </p>
            <p>
                @foreach ($units as $value => $label)
                    <label><input type="radio" name="unit" value="{{ $value }}" @checked(old('unit', 'articles') === $value)> {{ $label }}</label><br>
                @endforeach
                <span class="text-muted">子カテゴリの記事の案は子カテゴリを、足りない子カテゴリの案は親カテゴリを選んでください。</span>
            </p>
            <p><label>補足（重点を置きたい内容・対象の読者など）<br><textarea name="notes" rows="2" style="width:100%;">{{ old('notes') }}</textarea></label></p>
            @include('materials.partials.method', ['method' => config('blogos.ai.methods.topic_planning', 'api'), 'webSearch' => true, 'prefix' => 'topic'])
            <p class="text-muted">Web検索ありの API 実行で、1回あたり約 $0.05 です。結果は下の「確認待ちの案」に届きます。</p>
            <p><button type="submit">企画する</button></p>
        </form>
    </fieldset>

    <h2>確認待ちの案（{{ $pending->count() }}件）</h2>
    @if ($pending->isEmpty())
        <p>ありません。</p>
    @else
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>カテゴリ</th><th>案</th><th>優先度</th><th>理由・重複</th><th></th></tr></thead>
                <tbody>
                    @foreach ($pending as $suggestion)
                        @include('topics.partials.row', ['suggestion' => $suggestion, 'child' => false])
                        @foreach ($suggestion->articles->where('status', \App\Enums\SuggestionStatus::Pending) as $article)
                            @include('topics.partials.row', ['suggestion' => $article, 'child' => true])
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>採用した記事の案（最近の30件）</h2>
    @if ($accepted->isEmpty())
        <p>ありません。</p>
    @else
        <ul>
            @foreach ($accepted as $suggestion)
                <li>
                    {{ $suggestion->category?->name }}：{{ $suggestion->title }}
                    ・<a href="{{ route('ai.generations.create', \App\Support\TopicLinks::newArticle($suggestion)) }}">この案で新規記事を作る</a>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>AIを使わない手がかり</h2>
    <h3>記事の少ないカテゴリ（2件以下）</h3>
    <p class="text-muted">記事が1〜2件だけのカテゴリは、評価が低くなりやすいため、記事を増やすか、まとめて公開する計画を立ててください。</p>
    <ul>
        @foreach ($thin as $category)
            <li>{{ $categories->firstWhere('id', $category->parent_id)?->name ? $categories->firstWhere('id', $category->parent_id)->name . ' ＞ ' : '' }}{{ $category->name }}：{{ $counts[$category->id] ?? 0 }}記事
                ・<a href="{{ route('topics.index', ['category_id' => $category->id]) }}">このカテゴリを企画する</a></li>
        @endforeach
    </ul>

    <h3>記事が合っていない検索語句（直近90日・平均掲載順位が20位より下）</h3>
    @if ($queries->isEmpty())
        <p>ありません。</p>
    @else
        <table class="data">
            <thead><tr><th>検索語句</th><th>表示回数</th><th>クリック</th><th>平均順位</th><th>表示された記事</th></tr></thead>
            <tbody>
                @foreach ($queries as $query)
                    <tr><td>{{ $query->query }}</td><td>{{ $query->impressions }}</td><td>{{ $query->clicks }}</td><td>{{ number_format((float) $query->position, 1) }}</td><td>{{ $query->title }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

@endsection
