{{-- サーバー情報の DB の欄（BlogOS の DB と、WordPress の DB で共通。D-71） --}}
<p>{{ $database['name'] }}（{{ $database['version'] }}）・{{ count($database['tables']) }}テーブル</p>
<table class="data">
    <tbody>
        <tr>
            <th>使用率</th>
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
        <thead><tr><th>テーブル</th><th>件数</th><th>容量（データと索引）</th></tr></thead>
        <tbody>
            @foreach ($database['tables'] as $table)
                <tr>
                    <td>{{ $table['name'] }}</td>
                    <td style="text-align:right;">{{ number_format($table['rows']) }}</td>
                    <td style="text-align:right;">{{ $size($table['bytes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</details>
