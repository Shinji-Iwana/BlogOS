{{--
    編集案の一覧
--}}

@extends('layouts.app')

@section('content')

    <h1>編集案の一覧（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('articles.index', ['type' => 'posts']) }}">投稿の一覧</a>
        ・<a href="{{ route('push-operations.index') }}">反映記録</a>
        ・
        @if ($includeClosed)
            <a href="{{ route('drafts.index') }}">作業中のものだけ表示する</a>
        @else
            <a href="{{ route('drafts.index', ['all' => 1]) }}">反映済み・破棄したものも表示する</a>
        @endif
    </p>

    @include('partials.flash')

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr><th>#</th><th>タイトル</th><th>対象</th><th>状態</th><th>改修範囲</th><th>作成元</th><th>更新</th></tr>
            </thead>
            <tbody>
                @forelse ($drafts as $draft)
                    <tr>
                        <td><a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">{{ $draft->id }}</a></td>
                        <td>{{ $draft->title_raw ?: '（タイトルなし）' }}</td>
                        <td>{{ $draft->isNewArticle() ? '新規の' : '既存の' }}{{ $draft->target_type->label() }}</td>
                        <td>{{ $draft->state->label() }}</td>
                        <td>{{ $draft->revision_scope?->label() }}</td>
                        <td>{{ $draft->origin->label() }}（{{ $draft->creator?->name ?? '-' }}）</td>
                        <td>{{ \App\Support\DisplayTime::format($draft->updated_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
