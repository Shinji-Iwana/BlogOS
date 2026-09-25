{{--
    記事の一覧（投稿・固定ページ）。DBから表示し、WordPress APIは呼ばない
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $type === 'posts' ? '投稿' : '固定ページ' }}の一覧（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('articles.index', ['type' => $type === 'posts' ? 'pages' : 'posts']) }}">{{ $type === 'posts' ? '固定ページ' : '投稿' }}の一覧へ</a>
        ・<a href="{{ route('drafts.index') }}">編集案の一覧</a>
    </p>

    @include('partials.flash')

    <form method="POST" action="{{ route('drafts.store') }}" style="margin-bottom:10px;">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="article_type" value="{{ $type }}">
        <button type="submit">新しい{{ $type === 'posts' ? '投稿' : '固定ページ' }}の編集案を作る</button>
    </form>

    <form method="GET" action="{{ route('articles.index', ['type' => $type]) }}">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="タイトル・スラッグ">
        <select name="status">
            <option value="">ステータス：すべて</option>
            @foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
            @endforeach
        </select>
        <select name="work_status">
            <option value="">作業状態：すべて</option>
            @foreach ($workStatuses as $workStatus)
                <option value="{{ $workStatus->value }}" @selected(($filters['work_status'] ?? '') === $workStatus->value)>{{ $workStatus->label() }}</option>
            @endforeach
        </select>
        <button type="submit">絞り込む</button>
    </form>

    <p>{{ $articles->total() }}件（WordPress側で完全に削除されたものを除く）</p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th>タイトル</th>
                    <th>ステータス</th>
                    <th>記事種類</th>
                    <th>作業状態</th>
                    <th>編集案</th>
                    <th>公開日</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr>
                        <td><a href="{{ route('articles.show', ['type' => $type, 'id' => $article->id]) }}">{{ $article->title_raw ?: '（タイトルなし）' }}</a></td>
                        <td>{{ $article->status }}</td>
                        <td>{{ $article->article_type }}</td>
                        <td>{{ \App\Enums\WorkStatus::tryFrom((string) $article->work_status)?->label() ?? '未着手' }}</td>
                        <td>
                            @if ($article->active_draft_id)
                                <a href="{{ route('drafts.edit', ['id' => $article->active_draft_id]) }}">#{{ $article->active_draft_id }}</a>
                            @endif
                        </td>
                        <td>{{ \App\Support\DisplayTime::format($article->wordpress_date_gmt, 'Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $articles->links() }}

@endsection
