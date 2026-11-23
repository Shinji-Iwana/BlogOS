{{--
    BlogOS の DB のテーブルの一覧（DB確認画面。取り込んだ WordPress のデータの一覧と同じ形。D-80）。
    長い文章・JSON の列は、詳細だけで出す。秘密の値は「****（説明）」と出す
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $label ? "{$label}（{$table}）" : $table }}</h1>

    {{-- XServer情報の DB の一覧から開くテーブルだけ、戻るリンクを出す。取り込んだ WordPress のデータのテーブル（投稿など）は、レコードの詳細の「参照している行」からだけ開くため出さない（D-97-03） --}}
    @if (! \App\Support\DatabaseTables::isWordPress($table))
        <p><a href="{{ route('server.index') }}">XServer情報に戻る</a></p>
    @endif

    {{-- 絞り込みの条件（取り込んだ WordPress のデータの一覧と同じ形。パネルの題名の英字の札（data-code）は、ironman だけで出す） --}}
    <section class="panel">
    <h2 data-code="FILTER">条件</h2>
    @if ($filterColumn !== null)
        <p>
            {{ $filterColumn }} が {{ $filterValue }} の行（ブログでは絞らない）
            ・<a href="{{ route('database.tables.index', ['table' => $table]) }}">解除する</a>
        </p>
    @elseif ($scoped)
        <p class="text-muted">選択中のブログ{{ $blog ? '（' . $blog->display_name . '）' : '' }}の行と、どのブログにも属さない行を出します。</p>
    {{-- どの行を出しているかを、全ての一覧で補足する（D-80-06） --}}
    @elseif (\App\Support\DatabaseTables::hasColumn($table, 'blog_id'))
        <p class="text-muted">ブログの情報のテーブルのため、選択中のブログによらず、全てのブログの行を出します。</p>
    @else
        <p class="text-muted">ブログの列（blog_id）を持たないテーブルのため、選択中のブログによらず、全ての行を出します。</p>
    @endif
    @if ($searchColumns !== [])
        <form method="GET" action="{{ route('database.tables.index', ['table' => $table]) }}">
            @if ($filterColumn !== null)
                <input type="hidden" name="column" value="{{ $filterColumn }}">
                <input type="hidden" name="value" value="{{ $filterValue }}">
            @endif
            <input type="text" name="q" value="{{ $keyword }}" placeholder="{{ implode('・', $searchColumns) }}で絞り込み">
            <button class="btn-secondary" type="submit">絞り込む</button>
        </form>
    @else
        <p class="text-muted">文字で絞り込める列はありません。</p>
    @endif
    </section>

    {{-- レコードの一覧（1ページ最大50件。並びは、主キーの降順＝新しい順） --}}
    <section class="panel">
    <h2 data-code="RECORD">レコード</h2>
    @include('partials.history-count', ['paginator' => $records, 'order' => $primaryKey ? $primaryKey . '降順' : null])

    <div style="overflow-x:auto;">
        <table class="data">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        @foreach ($columns as $column)
                            @php
                                $value = \App\Support\DatabaseTables::display($table, $column, $record->{$column});
                                $shown = \Illuminate\Support\Str::limit((string) $value, 80);
                            @endphp
                            {{-- 主キーは、詳細へのリンク。URL の値は、別のタブで開くリンク（D-113-01） --}}
                            <td>@if ($column === $primaryKey)<a href="{{ \App\Support\DatabaseTables::recordUrl($table, $record->{$column}) }}">{{ $shown }}</a>@else{{ \App\Support\UrlLink::html($value, 80) }}@endif</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(1, count($columns)) }}">ありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $records->links() }}
    </section>

@endsection
