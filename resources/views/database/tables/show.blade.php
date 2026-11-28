{{--
    BlogOS の DB のテーブルの1レコード（DB確認画面。取り込んだ WordPress のデータの詳細と同じ形。D-80）。
    詳細（全ての列。外部キーの列は、値の横に「（リンク）」）・関連（このレコードを指しているテーブルと件数。あれば）・変更履歴（履歴のテーブルがあれば）を出す。
    秘密の値は「****（説明）」と出す
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $label ? "{$label}（{$table}）" : $table }}#{{ $record->{$primaryKey} }}</h1>

    {{-- 上のリンクは「（テーブルの日本語名）一覧へ」（日本語名がなければテーブル名。WordPress の DB のテーブルの詳細と同じ形。D-132-03） --}}
    <p><a href="{{ route('database.tables.index', ['table' => $table]) }}">{{ $label ?? $table }}一覧へ</a></p>

    @php
        // つながる先のリンクを「 / 」でつなぐ（D-132-02）（「（」「）」の内側に空白が入らないよう、1つの文字列にする。文字は e() で書き換えてから出す）
        $relationLinks = fn (array $items) => collect($items)->map(fn ($item) => $item['url'] ? '<a href="' . e($item['url']) . '">' . e($item['text']) . '</a>' : e($item['text']))->implode(' / ');
    @endphp

    {{-- ページ内のパネルの題名は日本語だけ（英字の札を付けない。D-117-01） --}}
    <section class="panel">
    <h2>詳細</h2>
    <div style="overflow-x:auto;">
        <table class="data">
            <tbody>
                @foreach ((array) $record as $column => $value)
                    @php
                        $shown = \App\Support\DatabaseTables::display($table, $column, $value);
                    @endphp
                    <tr>
                        {{-- 項目名と「?」は折り返さない（列の幅は、項目名に合わせる） --}}
                        <th style="vertical-align:top; white-space:nowrap; width:1%;">
                            {{ $column }}
                            {{-- 何を保持する列かの説明（App\Support\DatabaseColumns） --}}
                            @if ($columnNote = \App\Support\DatabaseColumns::describe($table, $column))
                                @include('partials.tip', ['tip' => $columnNote])
                            @endif
                        </th>
                        <td>
                            @if (is_string($shown) && mb_strlen($shown) > 300)
                                <details>
                                    <summary>{{ \Illuminate\Support\Str::limit($shown, 100) }}</summary>
                                    <pre style="white-space:pre-wrap; word-break:break-all;">{{ $shown }}</pre>
                                </details>
                            @else
                                {{ \App\Support\UrlLink::html($shown) }}
                            @endif
                            {{-- つながる先：値の横に「（リンク）」で出す。値が空なら出さない。
                                 つながる先がなく、説明に「値：意味」で書いた決まった値なら、値の横に「（日本語の名前）」を出す（リンクなし。D-133-02） --}}
                            @if (! empty($links[$column]))
                                （{!! $relationLinks($links[$column]) !!}）
                            @elseif (($valueLabel = \App\Support\ValueLabels::label(\App\Support\DatabaseColumns::describe($table, $column), $value)) !== null)
                                （{{ $valueLabel }}）
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    </section>

    {{-- 関連：このレコードを指しているほかのテーブルの行（1件以上のものだけ）。件数から、その行に絞った一覧を開く --}}
    @if ($references !== [])
        <section class="panel">
        <h2>関連 @include('partials.tip', ['tip' => 'このレコードを指している、ほかのテーブルの行の数です。件数を押すと、その行に絞った一覧を開きます。'])</h2>
        <table class="data">
            <thead><tr><th>テーブル</th><th>列</th><th>件数</th></tr></thead>
            <tbody>
                @foreach ($references as $reference)
                    <tr>
                        <td>{{ $reference['label'] ? "{$reference['label']}（{$reference['table']}）" : $reference['table'] }}</td>
                        <td>{{ $reference['column'] }}</td>
                        <td class="cell-num"><a href="{{ $reference['url'] }}">{{ number_format($reference['count']) }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </section>
    @endif

    {{-- 変更履歴：日時・変更の元・項目の行だけを出し、押すと、その履歴の全ての項目を開く（取り込んだ WordPress のデータの詳細と同じ形） --}}
    @if ($histories !== null)
        <section class="panel">
        <h2>変更履歴</h2>
        @include('partials.history-count', ['paginator' => $histories])

        @forelse ($histories as $history)
            <details class="record-history">
                {{-- 題名の項目は「 / 」でつなぐ（D-114-01） --}}
                <summary>{{ \App\Support\DisplayTime::format($history->changed_at) }} / {{ $history->source }} / {{ $history->field }}</summary>
                <div style="overflow-x:auto;">
                    <table class="data">
                        <tbody>
                            @foreach ((array) $history as $column => $value)
                                @php
                                    // 変更前・変更後は、変わった項目の名前で、秘密の値を隠し、日時を日本時間にする。そのほか（changed_at など）は、列の名前で
                                    $shown = in_array($column, ['old_value', 'new_value'], true)
                                        ? \App\Support\DatabaseTables::display($table, (string) $history->field, $value)
                                        : \App\Support\DatabaseTables::display($historyTable, $column, $value);
                                @endphp
                                <tr>
                                    <th style="vertical-align:top; white-space:nowrap; width:1%;">
                                        {{ $column }}
                                        @if ($historyNote = \App\Support\DatabaseColumns::describe($historyTable, $column))
                                            @include('partials.tip', ['tip' => $historyNote])
                                        @endif
                                    </th>
                                    <td>
                                        @if (is_string($shown) && mb_strlen($shown) > 300)
                                            <details>
                                                <summary>{{ \Illuminate\Support\Str::limit($shown, 100) }}</summary>
                                                <pre style="white-space:pre-wrap; word-break:break-all;">{{ $shown }}</pre>
                                            </details>
                                        @else
                                            {{ \App\Support\UrlLink::html($shown) }}
                                        @endif
                                        {{-- 決まった値の「（日本語の名前）」（D-133-02）：変更前・変更後は、変わった項目の説明から。そのほかは、履歴の列の説明から --}}
                                        @if (! empty($historyLinks[$history->id][$column]))
                                            （{!! $relationLinks($historyLinks[$history->id][$column]) !!}）
                                        @elseif (($valueLabel = \App\Support\ValueLabels::label(in_array($column, ['old_value', 'new_value'], true) ? \App\Support\DatabaseColumns::describe($table, (string) $history->field) : \App\Support\DatabaseColumns::describe($historyTable, $column), $value)) !== null)
                                            （{{ $valueLabel }}）
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @empty
            <p>履歴はありません。</p>
        @endforelse

        {{ $histories->links() }}
        </section>
    @endif

@endsection
