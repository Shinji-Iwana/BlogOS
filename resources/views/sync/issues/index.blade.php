{{--
    同期で見つかった、人の対応が必要な問題（sync_issues。D-15-08）

    問題そのものはWordPress側・BlogOS側で対応し、ここでは対応した内容を記録して解決済みにする。
--}}

@extends('layouts.app')

@section('content')

    <h1>同期の問題（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・
        @if ($showResolved)
            <a href="{{ route('sync.issues.index') }}">未解決の問題を見る</a>
        @else
            <a href="{{ route('sync.issues.index', ['resolved' => 1]) }}">解決済みの問題を見る（新しい順、最大200件）</a>
        @endif
    </p>

    @if (session('status'))
        <p style="color:#070;">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <div style="color:#b00;">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <p>{{ $showResolved ? '解決済み' : '未解決' }}：{{ $issues->count() }}件</p>

    @forelse ($issues as $issue)
        <section style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <p style="margin-top:0;">
                <strong>{{ $issue->issue_type->label() }}</strong>
                ・対象：{{ $issue->resource_type ?? '-' }}
                @if ($issue->resource_key !== null)
                    （{{ $issue->resource_key }}）
                @endif
                @if ($issue->post_id)
                    ・<a href="{{ route('database.wordpress-records.show', ['table' => 'posts', 'id' => $issue->post_id]) }}">投稿を見る</a>
                @endif
                @if ($issue->page_id)
                    ・<a href="{{ route('database.wordpress-records.show', ['table' => 'pages', 'id' => $issue->page_id]) }}">固定ページを見る</a>
                @endif
            </p>
            <p>{{ $issue->message }}</p>
            <p style="color:#666;">
                最初の検出：{{ \App\Support\DisplayTime::format($issue->first_detected_at) }} ・最後の検出：{{ \App\Support\DisplayTime::format($issue->last_detected_at) }}
                @if ($issue->error_status)
                    ・HTTP {{ $issue->error_status }}
                @endif
            </p>

            @if ($issue->error_body !== null)
                <details>
                    <summary>応答本文</summary>
                    <pre style="white-space:pre-wrap; word-break:break-all; max-height:300px; overflow:auto;">{{ $issue->error_body }}</pre>
                </details>
            @endif

            @if ($issue->resolved_at === null)
                <form method="POST" action="{{ route('sync.issues.resolve', ['id' => $issue->id]) }}">
                    @csrf
                    @include('partials.selected-blog-field')
                    <label>
                        対応した内容：
                        <input type="text" name="resolution" maxlength="2000" required style="width:100%; max-width:500px;">
                    </label>
                    <button type="submit">解決済みにする</button>
                </form>
            @else
                <p>
                    解決：{{ \App\Support\DisplayTime::format($issue->resolved_at) }}（{{ $issue->resolvedBy?->name ?? '-' }}）
                    ・{{ $issue->resolution }}
                </p>
            @endif
        </section>
    @empty
        <p>ありません。</p>
    @endforelse

@endsection
