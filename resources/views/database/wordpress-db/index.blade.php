{{--
    WordPress の DB のテーブルの一覧（XServer情報の DB（WordPress）から開く。BlogOS の DB の全テーブルの一覧と同じ形。D-81）。
    読むだけ。とても長い文章の列は、詳細だけで出す。秘密の値は「****（説明）」と出す
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $label ? "{$label}（{$table}）" : $table }}</h1>

    <p>
        <a href="{{ route('server.index') }}">XServer情報に戻る</a>
        <span class="text-muted">・DB：{{ $schema }}（WordPress の DB。読むだけで、書き換えません）</span>
    </p>

    {{-- 絞り込みの条件（パネルの題名の英字の札（data-code）は、ironman だけで出す） --}}
    <section class="panel">
    <h2 data-code="FILTER">条件</h2>
    @if ($filterColumn !== null)
        <p>
            {{ $filterColumn }} が {{ $filterValue }} の行
            ・<a href="{{ route('database.wordpress-db.index', ['schema' => $schema, 'table' => $table]) }}">解除する</a>
        </p>
    @endif
    @if ($searchColumns !== [])
        <form method="GET" action="{{ route('database.wordpress-db.index', ['schema' => $schema, 'table' => $table]) }}">
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
                    @php
                        $row = (array) $record;
                    @endphp
                    <tr>
                        @foreach ($columns as $column)
                            @php
                                $shown = \Illuminate\Support\Str::limit((string) $wp->display($schema, $table, $column, $row[$column], $row), 80);
                            @endphp
                            {{-- 主キーは、詳細へのリンク --}}
                            <td>@if ($column === $primaryKey)<a href="{{ route('database.wordpress-db.show', ['schema' => $schema, 'table' => $table, 'id' => $row[$column]]) }}">{{ $shown }}</a>@else{{ $shown }}@endif</td>
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
