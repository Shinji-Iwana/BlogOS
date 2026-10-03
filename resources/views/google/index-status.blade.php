{{--
    記事のインデックスの登録状態（D-37）
--}}

@extends('layouts.app')

@section('content')

    <h1>インデックスの登録状態（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('articles.titles') }}">タイトル・メタディスクリプションの改善の候補</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'revision', 'target' => 'not_indexed']) }}">インデックス未登録の記事をまとめて改修する</a>
    </p>

    @include('partials.flash')

    @unless ($configured)
        <p class="text-error">Search Console のプロパティが設定されていません。<a href="{{ route('google.settings') }}">Google連携の設定</a>で選んでください。</p>
    @else
        <p class="text-muted">
            Search Console の URL 検査で、公開中の記事が Google に登録されているかを調べます（毎日 05:30 に、まだ調べていない記事・反映で変わった記事・前に調べてから日数が過ぎた記事を調べます）。
            今、調べる順番を待っている記事：{{ $due }}件。
        </p>
        <form method="POST" action="{{ route('google.index-status.run') }}" style="margin-bottom:8px;">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit">今すぐ調べる</button>
            <label><input type="checkbox" name="all" value="1"> 日数が過ぎていない記事も調べ直す</label>
        </form>
    @endunless

    <h2>分類ごとの件数</h2>
    <ul>
        @foreach (\App\Enums\GoogleIndexCategory::cases() as $option)
            @if ($counts[$option->value] ?? 0)
                <li>
                    <a href="{{ route('google.index-status', ['category' => $option->value]) }}">{{ $option->label() }}</a>：{{ $counts[$option->value] }}件
                    @if ($option->advice())<br><span class="text-muted">{{ $option->advice() }}</span>@endif
                </li>
            @endif
        @endforeach
        @if ($counts['error'] ?? 0)<li class="text-error">調べられなかった：{{ $counts['error'] }}件</li>@endif
    </ul>

    <h2>{{ $category ? $category->label() . 'の記事' : '登録済み以外の記事' }}（{{ $statuses->count() }}件）</h2>
    @if ($category)<p><a href="{{ route('google.index-status') }}">登録済み以外の記事をすべて表示する</a></p>@endif

    @if ($statuses->isEmpty())
        <p>ありません。</p>
    @else
        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>記事</th><th>分類</th><th>最後に Google が読んだ日時</th><th>調べた日時</th><th>前の分類</th><th></th></tr></thead>
                <tbody>
                    @foreach ($statuses as $status)
                        @php $article = $status->post ?? $status->page; @endphp
                        <tr>
                            <td style="max-width:420px;"><a href="{{ route('articles.show', ['type' => $status->post_id ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a></td>
                            <td>
                                {{ $status->category?->label() ?? '調べられなかった' }}
                                @if ($status->error)<br><span class="text-error">{{ \Illuminate\Support\Str::limit($status->error, 120) }}</span>@endif
                            </td>
                            <td>{{ $status->last_crawl_at ? \App\Support\DisplayTime::format($status->last_crawl_at) : '-' }}</td>
                            <td>{{ $status->inspected_at ? \App\Support\DisplayTime::format($status->inspected_at) : '-' }}</td>
                            <td>{{ $status->previous_category?->label() }}{{ $status->category_changed_at ? '（' . \App\Support\DisplayTime::format($status->category_changed_at, 'Y-m-d') . ' に変化）' : '' }}</td>
                            <td><a href="{{ route('ai.generations.create', ['mode' => 'revision', 'target' => ($status->post_id ? 'posts:' : 'pages:') . $article->id]) }}">AIで改修案を作る</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
