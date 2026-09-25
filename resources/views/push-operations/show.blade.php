{{--
    反映記録の詳細と、結果が不明な反映の確認（WORDPRESS_API 24章）
--}}

@extends('layouts.app')

@section('content')

    @php $target = $operation->post ?? $operation->page; @endphp
    @php $termType = $operation->category ? 'categories' : ($operation->tag ? 'tags' : ($operation->media ? 'media' : null)); @endphp

    <h1>反映記録 #{{ $operation->id }}：{{ $operation->state->label() }}</h1>

    <p>
        <a href="{{ route('push-operations.index') }}">反映記録の一覧</a>
        @if ($operation->draft)
            ・<a href="{{ route('drafts.edit', ['id' => $operation->draft->id]) }}">編集案 #{{ $operation->draft->id }}</a>
        @endif
        @if ($target)
            ・<a href="{{ route('articles.show', ['type' => $operation->post ? 'posts' : 'pages', 'id' => $target->id]) }}">記事</a>
        @endif
        @if ($termType)
            ・<a href="{{ route('database.wordpress-records.show', ['table' => $termType, 'id' => $operation->target()->id]) }}">対象のDBの値と変更履歴</a>
        @endif
    </p>

    @include('partials.flash')

    @if ($operation->state === \App\Enums\PushState::Completed)
        <p style="color:#070;"><strong>反映が完了しました。</strong>DBはWordPressが返した内容で更新しています。</p>
    @elseif ($operation->state === \App\Enums\PushState::Failed)
        <p style="color:#b00;"><strong>反映できませんでした。</strong>WordPressは変更されていません。編集案から、やり直せます。</p>
    @elseif ($operation->state === \App\Enums\PushState::WpSucceeded)
        <p style="color:#b00;"><strong>WordPressへの反映は成功しましたが、DBの更新が完了していません。</strong>次の同期の最初に、回復処理で完了させます。</p>
    @endif

    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th style="text-align:left;">対象</th><td>{{ $operation->resource_type->label() }}：{{ $operation->targetLabel() }}</td></tr>
        <tr><th style="text-align:left;">操作</th><td>{{ $operation->operation->label() }}</td></tr>
        <tr><th style="text-align:left;">承認</th><td>{{ $operation->approver?->name ?? '-' }}</td></tr>
        <tr><th style="text-align:left;">日時</th><td>
            作成 {{ \App\Support\DisplayTime::format($operation->created_at) }}
            @if ($operation->sent_at) ／ 送信 {{ \App\Support\DisplayTime::format($operation->sent_at) }} @endif
            @if ($operation->completed_at) ／ 完了 {{ \App\Support\DisplayTime::format($operation->completed_at) }} @endif
            @if ($operation->failed_at) ／ 失敗 {{ \App\Support\DisplayTime::format($operation->failed_at) }} @endif
        </td></tr>
        <tr><th style="text-align:left;">WordPressの応答</th><td>ID：{{ $operation->wordpress_id ?? '-' }} ／ 更新日時：{{ \App\Support\DisplayTime::format($operation->response_modified_gmt) }}</td></tr>
        <tr><th style="text-align:left;">メッセージ</th><td>{{ $operation->message }}</td></tr>
        <tr><th style="text-align:left;">送信内容（要約）</th><td><pre style="white-space:pre-wrap; word-break:break-all; margin:0;">{{ json_encode($operation->request_summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>
    </table>

    @if ($operation->error_body !== null)
        <details>
            <summary>エラーの応答本文（HTTP {{ $operation->error_status }}）</summary>
            <pre style="white-space:pre-wrap; word-break:break-all; max-height:300px; overflow:auto;">{{ $operation->error_body }}</pre>
        </details>
    @endif

    {{-- 結果が不明な反映の確認（WORDPRESS_API 24-3） --}}
    @if ($operation->state === \App\Enums\PushState::Unknown)
        <h2>結果の確認</h2>
        <p>WordPressの管理画面で、反映されたかどうかを確認してから選んでください。</p>

        @if ($operation->operation === \App\Enums\PushOperationType::Create)
            @if ($candidateError)
                <p style="color:#b00;">候補を取得できませんでした：{{ $candidateError }}</p>
            @endif

            <p>送信した時刻の少し前以降に更新された記事（作成されていれば、この中にあります）：</p>
            @forelse ($candidates as $candidate)
                <form method="POST" action="{{ route('push-operations.resolve', ['id' => $operation->id]) }}" style="margin:4px 0;">
                    @csrf
                    @include('partials.selected-blog-field')
                    <input type="hidden" name="result" value="applied">
                    <input type="hidden" name="wordpress_id" value="{{ $candidate['id'] }}">
                    ID {{ $candidate['id'] }}：{{ $candidate['title']['raw'] ?? $candidate['title']['rendered'] ?? '' }}（{{ $candidate['status'] ?? '' }}、{{ \App\Support\DisplayTime::format($candidate['modified_gmt'] ?? null) }}）
                    @if (($candidate['meta']['_blogos_draft_id'] ?? null) === $operation->draft?->uuid)
                        <strong>← 編集案のIDが一致</strong>
                    @endif
                    <button type="submit">この記事として確定する</button>
                </form>
            @empty
                <p>候補はありません。</p>
            @endforelse
        @else
            <form method="POST" action="{{ route('push-operations.resolve', ['id' => $operation->id]) }}" style="margin-bottom:8px;">
                @csrf
                @include('partials.selected-blog-field')
                <input type="hidden" name="result" value="applied">
                <button type="submit">反映されていた（WordPressの現在の内容をDBに取り込んで完了する）</button>
            </form>
        @endif

        <form method="POST" action="{{ route('push-operations.resolve', ['id' => $operation->id]) }}">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="result" value="not_applied">
            <button type="submit">反映されていなかった（失敗として記録し、編集案をやり直せるようにする）</button>
        </form>
    @endif

@endsection
