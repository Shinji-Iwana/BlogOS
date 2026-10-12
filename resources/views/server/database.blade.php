{{--
    XServer情報の DB の欄（BlogOS の DB と、WordPress の DB で共通。D-71）。
    $wordpressTables（BlogOS の DB だけで渡す）があれば、取り込んだ WordPress のデータのテーブルを、その画面と同じ形（名前（テーブル名）のリンク・WordPress側で削除済みの件数）で出す（D-71-07）。
    BlogOS の DB のほかのテーブルも「日本語名（テーブル名）」で出し、全テーブルの一覧へつなぐ（D-80）。
    $wordpressDb（WordPress の DB で渡す）があれば、テーブルを WordPress の DB の一覧へつなぐ（本体のテーブルは「日本語名（テーブル名）」。D-81）
    $breakdown（BlogOS の DB だけで渡す）があれば、ブログごとの内訳（使用率の表の行と、テーブルごとの件数／容量の列）を出す（容量は見積もり。D-71-08）
--}}
@php
    $wordpressTables ??= [];
    $wordpressDb ??= false;
    $breakdown ??= null;
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
        {{-- ブログごとの内訳（BlogOS の DB だけ。容量は見積もり。D-71-08） --}}
        @if ($breakdown)
            @foreach ($breakdown['buckets'] as $bucket)
                @php
                    $bytes = $breakdown['totals'][$bucket['key']]['bytes'];
                @endphp
                <tr>
                    <th>
                        うち {{ $bucket['label'] }}
                        @if ($loop->first)
                            @include('partials.tip', ['tip' => "全体の「データと索引」のうち、ブログごとの分の目安です。\nMySQL はテーブル単位でしか容量を持たないため、テーブルの容量を、行のデータの長さの割合でブログに分けて見積もっています（索引などの分も、同じ割合で分けています）。\nどのブログの行かは blog_id で決め、blog_id のないテーブルは親のテーブル（例：AIの一括処理の対象 → AIの一括処理）でたどります。\nどのブログにも属さない行（利用者・キュー・料金表など）と、行のないテーブルの容量（MySQL が最低限とる分）は「共通」に入れています。"])
                        @endif
                    </th>
                    <td>
                        {{ $size($bytes) }}
                        （@if ($database['capacity_bytes'])使用率 {{ number_format($bytes / $database['capacity_bytes'] * 100, 1) }}%・@endif全体の {{ $database['total_bytes'] > 0 ? number_format($bytes / $database['total_bytes'] * 100, 1) : '0.0' }}%）
                    </td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>
<details>
    <summary>テーブルごとの件数と容量（容量の大きい順）</summary>
    <div style="overflow-x:auto;">
    <table class="data">
        <thead>
            <tr>
                <th>テーブル</th><th>件数</th>@if ($wordpressTables !== [])<th>うちWordPress側で削除済み</th>@endif<th>容量（データと索引）</th>
                {{-- ブログごとの件数と容量（容量は見積もり。D-71-08） --}}
                @if ($breakdown)
                    @foreach ($breakdown['buckets'] as $bucket)
                        <th>{{ $bucket['label'] }}<br><span class="text-muted">件数／容量</span></th>
                    @endforeach
                @endif
            </tr>
        </thead>
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
                    @if ($breakdown)
                        @foreach ($breakdown['buckets'] as $bucket)
                            @php
                                $share = $breakdown['tables'][$table['name']][$bucket['key']] ?? null;
                            @endphp
                            {{-- 行がなければ空にする --}}
                            <td style="text-align:right; white-space:nowrap;">@if ($share){{ number_format($share['rows']) }}／{{ $size($share['bytes']) }}@endif</td>
                        @endforeach
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</details>
