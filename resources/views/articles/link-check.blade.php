{{--
    ブログ全体の内部リンクの確認（D-42）
--}}

@extends('layouts.app')

@section('content')

    <h1>内部リンクの確認（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('drafts.link-switch') }}">リンクの切り替え</a>・<a href="{{ route('ai.batches.index') }}">まとめて実行</a></p>

    @include('partials.flash')

    <p class="text-muted">
        同期で本文から取り出した内部リンクを確かめます（WordPress・外部のサイトにはアクセスしません。同期のたびに最新になります）。
        古い URL と、ロードマップのページがあるカテゴリの一覧へのリンクは、BlogOS が「リンクの切り替え」の編集案で直します（人が確認して反映）。
        リンク切れは、記事改修で AI に直させます（記事改修の指示文に、この記事のリンクの問題が入ります）。
        孤立記事・ロードマップに載っていない記事は、記事改修・新規記事作成の指示文で「リンクが少ない記事」として、関連記事・次に読む記事に優先して選ばせます。
    </p>

    <ul>
        @foreach ($kinds as $kind => $label)
            <li><a href="#{{ $kind }}">{{ $label }}</a>：{{ $counts[$kind] }}件</li>
        @endforeach
    </ul>

    @foreach (['broken', 'outdated', 'category'] as $kind)
        @php $rows = $links->get($kind, collect()); @endphp
        <h2 id="{{ $kind }}">{{ $kinds[$kind] }}（{{ $rows->count() }}件）</h2>
        @if ($kind === 'broken' && $rows->isNotEmpty())
            <p><a href="{{ route('ai.batches.create', ['mode' => 'revision', 'target' => 'broken_links']) }}">リンク切れがある記事（{{ $rows->map(fn ($row) => $row['source']::class . $row['source']->id)->unique()->count() }}記事）をまとめて改修する</a></p>
        @elseif ($rows->isNotEmpty())
            <p>「<a href="{{ route('drafts.link-switch') }}">リンクの切り替え</a>」で、直す編集案を確認して反映してください（同期・反映の後に自動で作ります。すぐに作るときは、その画面の「今すぐ調べて編集案を作る」）。</p>
        @endif
        @if ($rows->isEmpty())
            <p>ありません。</p>
        @else
            <table class="data">
                <thead><tr><th>リンク元の記事</th><th>リンクの文字</th><th>URL</th><th>内容</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td style="max-width:320px;"><a href="{{ route('articles.show', ['type' => $row['source'] instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $row['source']->id]) }}">{{ $row['source']->title_raw ?: '（タイトルなし）' }}</a>@if ($row['source']->status !== 'publish')（{{ $row['source']->status }}）@endif</td>
                            <td style="max-width:220px;">{{ $row['link']->anchor_text }}</td>
                            <td style="word-break:break-all; max-width:280px;">{{ $row['link']->target_url }}@if ($row['fix'])<br>→ {{ $row['fix'] }}@endif</td>
                            <td style="max-width:320px;">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @foreach (['orphan', 'not_in_roadmap', 'no_outbound'] as $kind)
        @php $rows = $articles->get($kind, collect()); @endphp
        <h2 id="{{ $kind }}">{{ $kinds[$kind] }}（{{ $rows->count() }}件）</h2>
        @if ($rows->isEmpty())
            <p>ありません。</p>
        @else
            <details @if ($rows->count() <= 20) open @endif>
                <summary>一覧を開く</summary>
                <ul>
                    @foreach ($rows as $row)
                        <li>
                            <a href="{{ route('articles.show', ['type' => $row['article'] instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw ?: '（タイトルなし）' }}</a>
                            <span class="text-muted">— {{ $row['reason'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endforeach

@endsection
