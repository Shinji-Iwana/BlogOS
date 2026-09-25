{{--
    WordPress由来のテーブルの一覧（DB確認画面）。WordPress側で削除済みのものも含めて表示する
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $definition['label'] }}（{{ $table }}）</h1>

    <p><a href="{{ route('database.wordpress-records.tables') }}">データの一覧に戻る</a></p>

    <form method="GET" action="{{ route('database.wordpress-records.index', ['table' => $table]) }}">
        <input type="text" name="q" value="{{ $keyword }}" placeholder="{{ implode('・', $definition['search']) }}で絞り込み">
        <button type="submit">絞り込む</button>
    </form>

    <p>{{ $records->total() }}件</p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
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
                            <td>{{ \Illuminate\Support\Str::limit((string) $record->getRawOriginal($column), 80) }}</td>
                        @endforeach
                        <td>
                            @if ($record->wordpress_deleted_at)
                                <strong style="color:#b00;">{{ \App\Support\DisplayTime::format($record->wordpress_deleted_at) }}</strong>
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

@endsection
