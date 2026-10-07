{{--
    XServer情報（メニューの「情報 → XServer情報」。D-71）

    BlogOS が動いているサーバーの今の状態（サーバーの基本情報・DB・古い記録の削除・キュー・ファイルの容量）を、開いたときに読み取って出す。
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
    @endphp

    <h1>XServer情報</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        @if (config('blogos.server.panel_url'))
            ／ <a href="{{ config('blogos.server.panel_url') }}" target="_blank" rel="noopener">XServer のサーバーパネルを開く</a>
        @endif
    </p>

    <p class="text-muted">
        BlogOS が動いているサーバーの、今の状態です（この画面を開いたときに読み取ります。{{ \App\Support\DisplayTime::format(now()) }}）。
        読み取るだけで、DB への書き込み・削除はしません。契約上のディスクの残りは読み取れないため、XServer のサーバーパネルで確認してください。
    </p>

    <section class="panel">
    <h2>サーバーの基本情報</h2>
    <table class="data">
        <tbody>
            <tr><th>サーバー番号</th><td>{{ $machine['server_number'] ?? '読み取れない' }}</td></tr>
            <tr><th>ホスト名</th><td>{{ $machine['hostname'] ?? '読み取れない' }}</td></tr>
            <tr><th>IPアドレス</th><td>{{ $machine['ip'] ?? '読み取れない' }}</td></tr>
            <tr><th>PHP</th><td>{{ $machine['php_version'] }}</td></tr>
        </tbody>
    </table>
    <p class="text-muted">サーバー番号は、ホスト名の先頭（例：sv12345.xserver.jp の sv12345）から読みます。</p>
    </section>

    <section class="panel">
    <h2>DB（BlogOS）</h2>
    @include('server.database', ['database' => $database, 'size' => $size])
    <p class="text-muted">
        使用率は、データと索引の合計を上限で割って計算します（XServer のサーバーパネル（データベース → MySQL設定）の値とほぼ同じです）。
        上限は MySQL から読み取れないため、サーバーパネルの値（{{ config('blogos.server.db_capacity_mb') ?: '-' }} MB）を設定に入れています。
        記録を削除しても、MySQL の管理情報の容量はすぐには小さくならないことがあります。
    </p>
    </section>

    {{-- WordPress の DB（BlogOS の DB のユーザーにアクセス権がある DB。読むだけ。D-71-04） --}}
    @forelse ($others as $other)
        <section class="panel">
        <h2>
            DB（{{ $other['blog'] ? 'WordPress：' . $other['blog']->display_name : ($other['home'] ? 'WordPress' : 'ほかの DB') }}）
            @if ($other['blog'] && $other['blog']->id === ($selectedBlog?->id))
                <span class="text-muted">選択中のブログ</span>
            @endif
        </h2>
        @if ($other['home'])
            <p class="text-muted">サイトの URL：{{ $other['home'] }}{{ $other['blog'] ? '' : '（BlogOS に登録していないサイト）' }}</p>
        @endif
        @include('server.database', ['database' => $other['database'], 'size' => $size])
        </section>
    @empty
        <section class="panel">
        <h2>DB（WordPress）</h2>
        <p class="text-muted">
            BlogOS から見える WordPress の DB はありません。出すには、XServer のサーバーパネル（データベース → MySQL設定）で、WordPress の DB の「ユーザー設定」から、アクセス権所有ユーザーに BlogOS の DB のユーザーを足してください。
            BlogOS は読むだけで、WordPress の DB を書き換えません。
        </p>
        </section>
    @endforelse

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
