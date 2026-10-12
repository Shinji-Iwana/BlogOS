{{--
    WordPress の DB のテーブルの1レコード（BlogOS の DB の全テーブルの詳細と同じ形。D-81）。
    詳細（全ての列。つながりを決めた列は、値の横に「（リンク）」）・関連（このレコードを指しているテーブルと件数。あれば）を出す。
    WordPress は変更の履歴を持たないため、変更履歴のパネルはない。読むだけ。秘密の値は「****（説明）」と出す
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $label ? "{$label}（{$table}）" : $table }}#{{ $record[$primaryKey] }}</h1>

    <p>
        <a href="{{ route('database.wordpress-db.index', ['schema' => $schema, 'table' => $table]) }}">一覧に戻る</a>
        {{-- BlogOS に取り込んだ同じデータ --}}
        @if ($blogosLink)
            ・BlogOS に取り込んだデータ：<a href="{{ $blogosLink['url'] }}">{{ $blogosLink['text'] }}</a>
        @endif
        <span class="text-muted">・DB：{{ $schema }}（WordPress の DB。読むだけで、書き換えません）</span>
    </p>

    @php
        // つながる先のリンクを「・」でつなぐ（「（」「）」の内側に空白が入らないよう、1つの文字列にする。文字は e() で書き換えてから出す）
        $relationLinks = fn (array $items) => collect($items)->map(fn ($item) => $item['url'] ? '<a href="' . e($item['url']) . '">' . e($item['text']) . '</a>' : e($item['text']))->implode('・');
    @endphp

    {{-- パネルの題名の英字の札（data-code）は、ironman だけで出す --}}
    <section class="panel">
    <h2 data-code="DETAIL">詳細</h2>
    <div style="overflow-x:auto;">
        <table class="data">
            <tbody>
                @foreach ($record as $column => $value)
                    @php
                        $shown = $wp->display($schema, $table, $column, $value, $record);
                    @endphp
                    <tr>
                        {{-- 項目名と「?」は折り返さない（列の幅は、項目名に合わせる） --}}
                        <th style="text-align:left; vertical-align:top; white-space:nowrap; width:1%;">
                            {{ $column }}
                            {{-- 何を保持する列かの説明（本体のテーブルだけ。D-81-05） --}}
                            @if ($columnNote = $wp->describe($schema, $table, $column))
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
                                {{ $shown }}
                            @endif
                            {{-- つながる先：値の横に「（リンク）」で出す。値が空・0 なら出さない --}}
                            @if (! empty($links[$column]))
                                （{!! $relationLinks($links[$column]) !!}）
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
        <h2 data-code="RELATION">関連 @include('partials.tip', ['tip' => 'このレコードを指している、ほかのテーブルの行の数です。件数を押すと、その行に絞った一覧を開きます。'])</h2>
        <table class="data">
            <thead><tr><th>テーブル</th><th>列</th><th>件数</th></tr></thead>
            <tbody>
                @foreach ($references as $reference)
                    <tr>
                        <td>{{ $reference['label'] ? "{$reference['label']}（{$reference['table']}）" : $reference['table'] }}</td>
                        <td>{{ $reference['column'] }}</td>
                        <td style="text-align:right;"><a href="{{ $reference['url'] }}">{{ number_format($reference['count']) }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </section>
    @endif

@endsection
