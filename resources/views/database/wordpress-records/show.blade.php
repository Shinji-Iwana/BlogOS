{{--
    WordPress由来のテーブルの1レコード（DB確認画面）。詳細（全ての列）と変更履歴を表示する（D-05-10・D-72-09）。
    レコードの列として持つつながりは、詳細の値の横に「（リンク）」で出す（空なら出さない）。列ではなく別の表でつなぐもの（投稿のカテゴリ・タグ）は、
    関連のパネルに分けて出す（そのテーブルの値と、値ではないが関連しているものを分けて確かめるため。D-72-14）
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
        <p class="text-error"><strong>WordPress側で完全に削除されています（{{ \App\Support\DisplayTime::format($record->wordpress_deleted_at) }} に検知）。</strong></p>
    @endif

    {{-- パネルの題名の英字の札（data-code）は、ironman だけで出す --}}
    <section class="panel">
    <h2 data-code="DETAIL">詳細</h2>

    @php
        // 列として持つつながりは、列ごとに引けるようにする。列のないつながりは、関連のパネルに出す
        $relationsByColumn = collect($relations)->whereNotNull('column')->keyBy('column');
        $extraRelations = collect($relations)->whereNull('column');
        // つながる先のリンクを「・」でつなぐ（「（」「）」の内側に空白が入らないよう、1つの文字列にする。文字は e() で書き換えてから出す）
        $relationLinks = fn (array $items) => collect($items)->map(fn ($item) => $item['url'] ? '<a href="' . e($item['url']) . '">' . e($item['text']) . '</a>' : e($item['text']))->implode('・');
    @endphp

    <div style="overflow-x:auto;">
        <table class="data">
            <tbody>
                @foreach ($record->getAttributes() as $column => $value)
                    @php
                        // _at・_gmt の日時は日本時間で出す（D-72-04）
                        $value = \App\Support\DisplayTime::value($column, $value);
                    @endphp
                    <tr>
                        {{-- 項目名と「?」は折り返さない（列の幅は、項目名に合わせる） --}}
                        <th style="text-align:left; vertical-align:top; white-space:nowrap; width:1%;">
                            {{ $column }}
                            {{-- 何を保持する列かの説明（App\Support\WordPressColumns。D-72-08） --}}
                            @if ($columnNote = \App\Support\WordPressColumns::describe($table, $column))
                                @include('partials.tip', ['tip' => $columnNote])
                            @endif
                        </th>
                        <td>
                            @if (is_string($value) && mb_strlen($value) > 300)
                                <details>
                                    <summary>{{ \Illuminate\Support\Str::limit($value, 100) }}</summary>
                                    <pre style="white-space:pre-wrap; word-break:break-all;">{{ $value }}</pre>
                                </details>
                            @else
                                {{ $value }}
                                {{-- つながる先（関連）：値の横に「（リンク）」で出す。つながる先がなければ出さない --}}
                                @if (! empty($relationsByColumn[$column]['items']))
                                    （{!! $relationLinks($relationsByColumn[$column]['items']) !!}）
                                @endif
                                @if ($column === 'slug' && \App\Support\Slug::display($value) !== $value)
                                    <br><span class="text-muted">（読める形：{{ \App\Support\Slug::display($value) }}）</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    </section>

    {{-- 関連：レコードの値ではないが関連しているもの（列ではなく、別の表でつなぐ。投稿のカテゴリ・タグ。D-72-14） --}}
    @if ($extraRelations->isNotEmpty())
        <section class="panel">
        <h2 data-code="RELATION">関連 @include('partials.tip', ['tip' => 'このレコードの列としては持たず、別の表でつないでいるデータです（列として持つつながりは、詳細の値の横に出しています）。'])</h2>
        <table class="data">
            <tbody>
                @foreach ($extraRelations as $relation)
                    <tr>
                        <th style="text-align:left; vertical-align:top; white-space:nowrap; width:1%;">{{ $relation['label'] }}</th>
                        <td>{!! $relation['items'] !== [] ? $relationLinks($relation['items']) : 'なし' !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </section>
    @endif

    {{-- 変更履歴：日時・変更の元・項目の行だけを出し、押すと、その履歴の全ての項目を開く（詳細と同じ並べ方） --}}
    <section class="panel">
    <h2 data-code="HISTORY">変更履歴</h2>
    @include('partials.history-count', ['paginator' => $histories])

    @forelse ($histories as $history)
        <details class="record-history">
            <summary>{{ \App\Support\DisplayTime::format($history->changed_at) }}・{{ $history->source?->value }}・{{ $history->field }}</summary>
            <div style="overflow-x:auto;">
                <table class="data">
                    <tbody>
                        @foreach ($history->getAttributes() as $column => $value)
                            @php
                                // 変更前・変更後は、変わった項目の名前で日時を日本時間にする。そのほか（changed_at など）は、列の名前で
                                $shown = \App\Support\DisplayTime::value(in_array($column, ['old_value', 'new_value'], true) ? (string) $history->field : $column, $value);
                            @endphp
                            <tr>
                                <th style="text-align:left; vertical-align:top; white-space:nowrap; width:1%;">
                                    {{ $column }}
                                    {{-- 何を保持する列かの説明（App\Support\WordPressColumns::describeHistory） --}}
                                    @if ($historyNote = \App\Support\WordPressColumns::describeHistory($column, $record::historyForeignKey()))
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
                                        {{ $shown }}
                                    @endif
                                    {{-- つながる先：詳細と同じく、値の横に「（リンク）」で出す。なければ出さない（D-72-15） --}}
                                    @if (! empty($historyLinks[$history->id][$column]))
                                        （{!! $relationLinks($historyLinks[$history->id][$column]) !!}）
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

@endsection
