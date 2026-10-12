{{--
    XServer情報の DB の欄（BlogOS の DB と、WordPress の DB で共通。D-71）。
    $wordpressTables（BlogOS の DB だけで渡す）があれば、取り込んだ WordPress のデータのテーブルを、その画面と同じ形（名前（テーブル名）のリンク・WordPress側で削除済みの件数）で出す（D-71-07）。
    BlogOS の DB のほかのテーブルも「日本語名（テーブル名）」で出し、全テーブルの一覧へつなぐ（D-80）。
    $wordpressDb（WordPress の DB で渡す）があれば、テーブルを WordPress の DB の一覧へつなぐ（本体のテーブルは「日本語名（テーブル名）」。D-81）
--}}
@php
    $wordpressTables ??= [];
    $wordpressDb ??= false;
    $wp = $wordpressDb ? app(\App\Support\WordPressDatabaseTables::class) : null;
@endphp
<p>{{ $database['name'] }}（{{ $database['version'] }}）・{{ count($database['tables']) }}テーブル</p>
<table class="data">
    <tbody>
        <tr>
            <th>使用率 @include('partials.tip', ['tip' => "データと索引の合計を上限で割って計算します（XServer のサーバーパネル（データベース → MySQL設定）の値とほぼ同じです）。\n上限は MySQL から読み取れないため、サーバーパネルの値（" . (config('blogos.server.db_capacity_mb') ?: '-') . " MB）を設定に入れています。\n記録を削除しても、MySQL の管理情報の容量はすぐには小さくならないことがあります。"])</th>
            <td>
                @if ($database['usage_ratio'] !== null)
                    <strong>{{ number_format($database['usage_ratio'] * 100, 1) }}%</strong>（{{ number_format($database['total_bytes'] / 1024 ** 2, 1) }} MB ／ 上限 {{ number_format($database['capacity_bytes'] / 1024 ** 2) }} MB）
                @else
                    上限を設定していない（設定の BLOGOS_DB_CAPACITY_MB）
                @endif
            </td>
        </tr>
        <tr><th>データと索引</th><td>{{ $size($database['total_bytes']) }}</td></tr>
    </tbody>
</table>
<details>
    <summary>テーブルごとの件数と容量（容量の大きい順）</summary>
    <div style="overflow-x:auto;">
    <table class="data">
        <thead><tr><th>テーブル</th><th>件数</th>@if ($wordpressTables !== [])<th>うちWordPress側で削除済み</th>@endif<th>容量（データと索引）</th></tr></thead>
        <tbody>
            @foreach ($database['tables'] as $table)
                @php
                    $wordpressTable = $wordpressTables[$table['name']] ?? null;
                @endphp
                <tr>
                    @if ($wordpressTable)
                        {{-- 一覧は選択中のブログの分を出すため、ブログを選んでいなければリンクにしない --}}
                        <td>@if ($selectedBlog)<a href="{{ route('database.wordpress-records.index', ['table' => $table['name']]) }}">{{ $wordpressTable['label'] }}</a>@else{{ $wordpressTable['label'] }}@endif（{{ $table['name'] }}）</td>
                    @elseif ($wordpressTables !== [])
                        {{-- BlogOS の DB のほかのテーブルも「日本語名（テーブル名）」で、日本語名を一覧へのリンクにする（日本語名がなければテーブル名のリンク。D-80） --}}
                        <td><a href="{{ route('database.tables.index', ['table' => $table['name']]) }}">{{ \App\Support\DatabaseTables::label($table['name']) ?? $table['name'] }}</a>@if (\App\Support\DatabaseTables::label($table['name']))（{{ $table['name'] }}）@endif</td>
                    @elseif ($wp)
                        @php
                            $wpLabel = $wp->label($database['name'], $table['name']);
                        @endphp
                        <td><a href="{{ route('database.wordpress-db.index', ['schema' => $database['name'], 'table' => $table['name']]) }}">{{ $wpLabel ?? $table['name'] }}</a>@if ($wpLabel)（{{ $table['name'] }}）@endif</td>
                    @else
                        <td>{{ $table['name'] }}</td>
                    @endif
                    <td style="text-align:right;">{{ number_format($table['rows']) }}</td>
                    @if ($wordpressTables !== [])
                        <td style="text-align:right;">{{ $wordpressTable ? number_format($wordpressTable['deleted']) : '' }}</td>
                    @endif
                    <td style="text-align:right;">{{ $size($table['bytes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</details>
