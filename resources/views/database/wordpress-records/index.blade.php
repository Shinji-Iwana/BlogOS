{{--
    WordPress由来のテーブルの一覧（DB確認画面）。WordPress側で削除済みのものも含めて表示する
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $definition['label'] }}（{{ $table }}）</h1>

    <p><a href="{{ route('database.wordpress-records.tables') }}">データの一覧に戻る</a></p>

    {{-- 絞り込みの条件（WordPress API情報の画面と同じ形。パネルの題名の英字の札（data-code）は、ironman だけで出す） --}}
    <section class="panel">
    <h2 data-code="FILTER">条件</h2>
    <form method="GET" action="{{ route('database.wordpress-records.index', ['table' => $table]) }}">
        <input type="text" name="q" value="{{ $keyword }}" placeholder="{{ implode('・', $definition['search']) }}で絞り込み">
        <button class="btn-secondary" type="submit">絞り込む</button>
    </form>
    </section>

    {{-- レコードの一覧（1ページ最大50件。並びは、一覧の最初の列の昇順） --}}
    <section class="panel">
    <h2 data-code="RECORD">レコード</h2>
    @include('partials.history-count', ['paginator' => $records, 'order' => $definition['columns'][0] . '昇順'])

    <div style="overflow-x:auto;">
        <table class="data">
            <thead>
                <tr>
                    <th>id</th>
                    @foreach ($definition['columns'] as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                    <th>WordPress側で削除</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td><a href="{{ route('database.wordpress-records.show', ['table' => $table, 'id' => $record->id]) }}">{{ $record->id }}</a></td>
                        @foreach ($definition['columns'] as $column)
                            <td>{{ \Illuminate\Support\Str::limit((string) ($column === 'slug' ? \App\Support\Slug::display($record->getRawOriginal($column)) : $record->getRawOriginal($column)), 80) }}</td>
                        @endforeach
                        <td>
                            @if ($record->wordpress_deleted_at)
                                <strong class="text-error">{{ \App\Support\DisplayTime::format($record->wordpress_deleted_at) }}</strong>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($definition['columns']) + 2 }}">ありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $records->links() }}
    </section>

@endsection
