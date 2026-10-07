{{--
    サーバー情報（メニューの「情報 → サーバー情報」。D-71）

    BlogOS が動いているサーバーの今の状態（サーバーの負荷・DB・古い記録の削除・キュー・ファイルの容量）を、開いたときに読み取って出す。
    読み取るだけで、DB への書き込み・削除はしない。全ブログ共通のため、ブログを選んでいなくても開ける。
--}}

@extends('layouts.app')

@section('content')

    @php
        $size = function (?int $bytes): string {
            if ($bytes === null) {
                return '読み取れない';
            }
            foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $base) {
                if ($bytes >= $base) {
                    return number_format($bytes / $base, 1) . ' ' . $unit;
                }
            }

            return number_format($bytes) . ' B';
        };
        $load = $server['load'];
        $cpus = $server['cpus'];
    @endphp

    <h1>サーバー情報</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <p class="text-muted">
        BlogOS が動いているサーバーの、今の状態です（この画面を開いたときに読み取ります。{{ \App\Support\DisplayTime::format(now()) }}）。
        読み取るだけで、DB への書き込み・削除はしません。契約上のディスクの残りは読み取れないため、XServer のサーバーパネルで確認してください。
    </p>

    <section class="panel">
    <h2>サーバーの負荷</h2>
    <table class="data">
        <tbody>
            <tr>
                <th>ロードアベレージ</th>
                <td>
                    @if ($load)
                        直近1分 {{ number_format($load[0], 2) }}・5分 {{ number_format($load[1], 2) }}・15分 {{ number_format($load[2], 2) }}
                        @if ($cpus)
                            （CPU {{ $cpus }}個。1個あたり 直近5分 {{ number_format($load[1] / $cpus, 2) }}）
                        @endif
                    @else
                        読み取れない（このサーバーでは使えない）
                    @endif
                </td>
            </tr>
            <tr><th>PHP</th><td>{{ $server['php_version'] }}（{{ $server['os'] }}）</td></tr>
            <tr><th>PHP が使えるメモリの上限</th><td>{{ $server['memory_limit'] }}（この画面を作るのに使ったメモリ：{{ $size($server['peak_memory']) }}）</td></tr>
            <tr><th>画面の処理の時間の上限</th><td>{{ $server['max_execution_time'] === '0' ? 'なし' : $server['max_execution_time'] . '秒' }}</td></tr>
        </tbody>
    </table>
    <p class="text-muted">
        ロードアベレージは、処理を待っている仕事の数の平均です。CPU 1個あたりで 1 を超えると、処理が待たされ始めます。
        XServer は共用のサーバーのため、同じサーバーのほかの利用者の分も含みます（BlogOS だけの負荷ではありません）。
        画面のアニメーションは見ている人のブラウザで動くため、サーバーの負荷にはなりません。
    </p>
    </section>

    <section class="panel">
    <h2>DB</h2>
    <p>{{ $database['name'] }}（{{ $database['version'] }}）・{{ count($database['tables']) }}テーブル</p>
    <table class="data">
        <tbody>
            <tr>
                <th>使用率</th>
                <td>
                    @if ($database['usage_ratio'] !== null)
                        <strong>{{ number_format($database['usage_ratio'] * 100, 1) }}%</strong>（{{ $size($database['total_bytes']) }} ／ 上限 {{ $size($database['capacity_bytes']) }}）
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
    <p class="text-muted">
        使用率は、データと索引の合計を上限で割って計算します（XServer のサーバーパネル（データベース → MySQL設定）の値とほぼ同じです）。
        上限は MySQL から読み取れないため、サーバーパネルの値（{{ config('blogos.server.db_capacity_mb') ?: '-' }} MB）を設定に入れています。
        記録を削除しても、MySQL の管理情報の容量はすぐには小さくならないことがあります。
    </p>
    </section>

    <section class="panel">
    <h2>古い記録の削除</h2>
    <p class="text-muted">定期実行の「古い記録の削除」が消す記録です。「今削除の対象」が 0 なら、削除は働いています（次の定期実行で消える分だけが残ります）。</p>
    <div style="overflow-x:auto;">
    <table class="data">
        <thead><tr><th>記録</th><th>削除する条件</th><th>件数</th><th>一番古い記録</th><th>今削除の対象</th></tr></thead>
        <tbody>
            @foreach ($prunable as $item)
                <tr>
                    <td>{{ $item['label'] }}<br><span class="text-muted">{{ $item['table'] }}</span></td>
                    <td>{{ $item['rule'] }}</td>
                    <td style="text-align:right;">{{ number_format($item['rows']) }}</td>
                    <td>{{ $item['oldest'] ? \App\Support\DisplayTime::format($item['oldest'], 'Y-m-d') : '-' }}</td>
                    <td style="text-align:right;">{{ number_format($item['prunable']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <p class="text-muted">実行の記録は、メニューの「履歴 → <a href="{{ route('scheduled-tasks.runs') }}">定期実行履歴</a>」にあります。</p>
    </section>

    <section class="panel">
    <h2>キュー</h2>
    <table class="data">
        <tbody>
            <tr>
                <th>待っている処理</th>
                <td>
                    {{ number_format($queue['pending']) }}件
                    @if ($queue['by_queue'] !== [])
                        （@foreach ($queue['by_queue'] as $name => $total){{ $loop->first ? '' : '・' }}{{ $name }} {{ number_format($total) }}件@endforeach）
                    @endif
                    @if ($queue['oldest_pending'])
                        <br><span class="text-muted">一番古いもの：{{ \App\Support\DisplayTime::format(\Illuminate\Support\Carbon::createFromTimestamp($queue['oldest_pending'])) }}</span>
                    @endif
                </td>
            </tr>
            <tr><th>処理中</th><td>{{ number_format($queue['reserved']) }}件</td></tr>
            <tr>
                <th>失敗した処理</th>
                <td>
                    {{ number_format($queue['failed']) }}件
                    @if ($queue['last_failed'])
                        <span class="text-muted">（最後：{{ \App\Support\DisplayTime::format($queue['last_failed']) }}）</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
    <p class="text-muted">待っている処理が減らない・一番古いものが古いままの場合は、queue:work が止まっている可能性があります。</p>
    </section>

    <section class="panel">
    <h2>ファイル</h2>
    <table class="data">
        <thead><tr><th>フォルダ</th><th>容量</th><th>ファイルの数</th></tr></thead>
        <tbody>
            @foreach ($files as $item)
                <tr>
                    <td>{{ $item['label'] }}<br><span class="text-muted">{{ $item['path'] }}</span></td>
                    <td style="text-align:right;">{{ $size($item['bytes']) }}</td>
                    <td style="text-align:right;">{{ $item['files'] === null ? '-' : number_format($item['files']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="text-muted">BlogOS のフォルダの中だけです。</p>
    </section>

@endsection
