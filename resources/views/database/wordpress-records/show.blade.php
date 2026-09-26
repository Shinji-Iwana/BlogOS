{{--
    WordPress由来のテーブルの1レコード（DB確認画面）。全ての列と変更履歴を表示する（D-05-10）
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $definition['label'] }}（{{ $table }}）#{{ $record->id }}</h1>

    <p>
        <a href="{{ route('database.wordpress-records.index', ['table' => $table]) }}">一覧に戻る</a>
        @if (in_array($table, ['posts', 'pages'], true))
            ・<a href="{{ route('articles.show', ['type' => $table, 'id' => $record->id]) }}">記事の画面へ（編集案・管理情報）</a>
        @elseif (in_array($table, ['categories', 'tags', 'media'], true))
            ・<a href="{{ route('terms.edit', ['type' => $table, 'id' => $record->id]) }}">情報の更新・削除（WordPressへ反映）</a>
        @endif
    </p>

    @if ($record->wordpress_deleted_at)
        <p style="color:#b00;"><strong>WordPress側で完全に削除されています（{{ \App\Support\DisplayTime::format($record->wordpress_deleted_at) }} に検知）。</strong></p>
    @endif

    <h2>列の値</h2>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <tbody>
                @foreach ($record->getAttributes() as $column => $value)
                    <tr>
                        <th style="text-align:left; vertical-align:top;">{{ $column }}</th>
                        <td>
                            @if (is_string($value) && mb_strlen($value) > 300)
                                <details>
                                    <summary>{{ \Illuminate\Support\Str::limit($value, 100) }}</summary>
                                    <pre style="white-space:pre-wrap; word-break:break-all;">{{ $value }}</pre>
                                </details>
                            @else
                                {{ $value }}
                                @if ($column === 'slug' && \App\Support\Slug::display($value) !== $value)
                                    <br><span style="color:#666;">（読める形：{{ \App\Support\Slug::display($value) }}）</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($record instanceof \App\Models\Post)
        <h2>カテゴリ・タグ</h2>
        <p>
            カテゴリ：
            @forelse ($record->categories as $category)
                <a href="{{ route('database.wordpress-records.show', ['table' => 'categories', 'id' => $category->id]) }}">{{ $category->name }}</a>
            @empty
                なし
            @endforelse
        </p>
        <p>
            タグ：
            @forelse ($record->tags as $tag)
                <a href="{{ route('database.wordpress-records.show', ['table' => 'tags', 'id' => $tag->id]) }}">{{ $tag->name }}</a>
            @empty
                なし
            @endforelse
        </p>
    @endif

    <h2>変更履歴（新しい順、最大500件）</h2>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>変更の元</th>
                    <th>同期</th>
                    <th>項目</th>
                    <th>変更前</th>
                    <th>変更後</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($histories as $history)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($history->changed_at) }}</td>
                        <td>{{ $history->source?->value }}</td>
                        <td>{{ $history->sync_run_id ? '#' . $history->sync_run_id : '' }}</td>
                        <td>{{ $history->field }}</td>
                        @foreach (['old_value', 'new_value'] as $valueColumn)
                            <td>
                                @if ($history->{$valueColumn} !== null && mb_strlen($history->{$valueColumn}) > 200)
                                    <details>
                                        <summary>{{ \Illuminate\Support\Str::limit($history->{$valueColumn}, 80) }}</summary>
                                        <pre style="white-space:pre-wrap; word-break:break-all;">{{ $history->{$valueColumn} }}</pre>
                                    </details>
                                @else
                                    {{ $history->{$valueColumn} }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">履歴はありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
