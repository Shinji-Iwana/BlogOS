{{--
    WordPress の DB のテーブルの1レコード（BlogOS の DB の全テーブルの詳細と同じ形。D-81）。
    詳細（全ての列。つながりを決めた列は、値の横に「（リンク）」）・関連（このレコードを指しているテーブルと件数。あれば）を出す。
    WordPress は変更の履歴を持たないため、変更履歴のパネルはない。読むだけ。秘密の値は「****（説明）」と出す
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $label ? "{$label}（{$table}）" : $table }}#{{ $record[$primaryKey] }}</h1>

    {{-- 上のリンク：一覧は「（テーブルの日本語名）一覧へ」（日本語名がなければテーブル名。D-129-01）。BlogOS に取り込んだ同じデータがあれば、「 / 」でつなぎ（アコーディオンの題名と同じつなぎ方。D-129-02・D-114）、詳細の表のつながる先と同じ「BlogOS（名前 #ID）」の形で出す（D-129-04）。
         どの DB を見ているかは、リンクの下の黄色の注意に出す（D-129-03） --}}
    <p><a href="{{ route('database.wordpress-db.index', ['schema' => $schema, 'table' => $table]) }}">{{ $label ?? $table }}一覧へ</a>@if ($blogosLink) / BlogOS（<a href="{{ $blogosLink['url'] }}">{{ $blogosLink['text'] }}</a>）@endif</p>
    <p class="text-warn">DB：{{ $schema }}（WordPress の DB。読むだけで、書き換えません）</p>

    @php
        // つながる先のリンクを「・」でつなぐ（「（」「）」の内側に空白が入らないよう、1つの文字列にする。文字は e() で書き換えてから出す）
        $relationLinks = fn (array $items) => collect($items)->map(fn ($item) => $item['url'] ? '<a href="' . e($item['url']) . '">' . e($item['text']) . '</a>' : e($item['text']))->implode('・');
    @endphp

    {{-- ページ内のパネルの題名は日本語だけ（英字の札を付けない。D-117-01） --}}
    <section class="panel">
    <h2>詳細</h2>
    <div style="overflow-x:auto;">
        <table class="data">
            <tbody>
                @foreach ($record as $column => $value)
                    @php
                        $shown = $wp->display($schema, $table, $column, $value, $record);
                    @endphp
                    <tr>
                        {{-- 項目名と「?」は折り返さない（列の幅は、項目名に合わせる） --}}
                        <th style="vertical-align:top; white-space:nowrap; width:1%;">
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
                                {{ \App\Support\UrlLink::html($shown) }}
                            @endif
                            {{-- つながる先：値の横に「（リンク）」で出す。値が空・0 なら出さない --}}
                            @if (! empty($links[$column]))
                                （{!! $relationLinks($links[$column]) !!}）
                            @endif
                            {{-- 決まった値の日本語の名前：つながる先と同じ形の「（名前）」で、リンクはない（投稿の状態。D-129-05） --}}
                            @if (isset($valueLabels[$column]))
                                （{{ $valueLabels[$column] }}）
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

@endsection
